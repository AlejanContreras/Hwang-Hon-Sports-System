#!/bin/bash
# Ejecuta todas las baterías del backend, recargando la base antes de cada una
# (cada batería espera la semilla recién cargada). Ver README.md.
#
# PROTEGIDO (R-01, 31/10/2026): este script BORRA y RECREA una base de datos y VACÍA carpetas
# de Almacenamiento. Por eso solo corre cuando lo lanza pruebas/ejecutar_baterias_aisladas.py:
#   - DB_NAME debe ser exactamente "hwanghon_pruebas" (nunca "hwanghon", la de uso manual);
#   - PRUEBAS_AISLADAS=1 (solo lo pone ese ejecutor);
#   - BACKEND_PRUEBAS = carpeta backend/ de la COPIA aislada (pruebas/entorno/app/backend):
#     solo se vacían su Almacenamiento/documentos, generales y sesiones; no hay valor por defecto.
# Uso normal (desde la raíz del repositorio):  python pruebas/ejecutar_baterias_aisladas.py
#
# Variables (las fija el ejecutor aislado):
#   DB_NAME, PRUEBAS_AISLADAS, BACKEND_PRUEBAS     obligatorias (ver arriba)
#   URL_BASE=http://127.0.0.1:8090    servidor principal (router_pruebas.php)
#   URL_1M=http://127.0.0.1:8091      servidor con post_max_size=1M (calendario)
#   URL_LISTA=http://127.0.0.1:8092   servidor con ORIGENES_PERMITIDOS (revisión)
#   URL_APACHE=http://127.0.0.1:8095/backend/Public   secciones «Apache» (revisión y urls_limpias).
#       Por defecto el servidor AISLADO, nunca el Apache de XAMPP (que usa la base «hwanghon»).
#       Para verificar el mod_rewrite real, ver pruebas/backend/README.md (APACHE_REAL=1).
#   MEMORIA_LOG=<carpeta>/memoria.log el mismo que se pasó al servidor principal (reportes)
#   MYSQL="mysql -uroot"              cliente de MariaDB
#   SALTAR_RECUPERACION=1             si Config/correo.php está en modo smtp (auth, sección F)
set -u
DIR="$(cd "$(dirname "$0")" && pwd)"
RAIZ="$(cd "$DIR/../.." && pwd)"

detener() { echo "ejecutar_todo.sh: $1" >&2; echo "Usa: python pruebas/ejecutar_baterias_aisladas.py (base hwanghon_pruebas, copia aislada)." >&2; exit 1; }
[ "${PRUEBAS_AISLADAS:-}" = 1 ] || detener "se niega a correr fuera de ejecutar_baterias_aisladas.py (falta PRUEBAS_AISLADAS=1)."
[ "${DB_NAME:-}" = hwanghon_pruebas ] || detener "DB_NAME debe ser hwanghon_pruebas (llegó «${DB_NAME:-vacío}»). Nunca se recrea la base hwanghon."
[ -n "${BACKEND_PRUEBAS:-}" ] && [ -d "${BACKEND_PRUEBAS}/Almacenamiento" ] || detener "BACKEND_PRUEBAS debe ser la carpeta backend/ de la copia aislada (con su Almacenamiento/)."
case "$(cd "$BACKEND_PRUEBAS" && pwd)" in
  */pruebas/entorno/app/backend) ;;
  *) detener "BACKEND_PRUEBAS no es la copia aislada (pruebas/entorno/app/backend): nunca se vacía el Almacenamiento del proyecto." ;;
esac

# En Windows (Git Bash) PHP necesita rutas C:/...: "pwd -W" las da; en Linux, pwd.
BACKEND_W="$(cd "$BACKEND_PRUEBAS" && (pwd -W 2>/dev/null || pwd))"
WD="$(cd "$DIR" && (pwd -W 2>/dev/null || pwd))"
U="${URL_BASE:-http://127.0.0.1:8090}"
U1M="${URL_1M:-http://127.0.0.1:8091}"
ULISTA="${URL_LISTA:-http://127.0.0.1:8092}"
UAPACHE="${URL_APACHE:-http://127.0.0.1:8095/backend/Public}"
MEMORIA="${MEMORIA_LOG:-$WD/memoria.log}"
MYSQL="${MYSQL:-mysql -uroot}"
SALIDAS="$DIR/salidas"
mkdir -p "$SALIDAS"

# Los scripts SQL traen «CREATE DATABASE … hwanghon» / «USE hwanghon»: se redirigen a $DB_NAME al vuelo.
redirigir() { sed -e "s/CREATE DATABASE IF NOT EXISTS hwanghon\b/CREATE DATABASE IF NOT EXISTS $DB_NAME/I" -e "s/^USE hwanghon;/USE $DB_NAME;/I" "$1"; }

recargar() { # $1 = "cuentas" para cargar también las cuentas de prueba locales
  $MYSQL -e "DROP DATABASE IF EXISTS \`$DB_NAME\`"
  redirigir "$RAIZ/base_datos/esquema_hwanghon_v4.sql" | $MYSQL --default-character-set=utf8mb4
  redirigir "$RAIZ/base_datos/insercion_datos_prueba.sql" | $MYSQL --default-character-set=utf8mb4 "$DB_NAME"
  [ "${1:-}" = cuentas ] && redirigir "$RAIZ/base_datos/cuentas_prueba_locales.sql" | $MYSQL --default-character-set=utf8mb4 "$DB_NAME"
  rm -rf "$BACKEND_PRUEBAS/Almacenamiento/sesiones"
  find "$BACKEND_PRUEBAS/Almacenamiento/documentos" "$BACKEND_PRUEBAS/Almacenamiento/generales" -type f -delete 2>/dev/null
  return 0
}
total=0
correr() { # nombre, modo de base, comando...
  local n=$1 m=$2; shift 2
  recargar "$m"
  "$@" > "$SALIDAS/$n.txt" 2>&1; local e=$?
  local r; r=$(grep -a 'RESULTADO' "$SALIDAS/$n.txt")
  echo "$n: exit $e | $r"
  total=$((total + $(echo "$r" | grep -oE '[0-9]+ OK' | grep -oE '[0-9]+' || echo 0)))
}

correr repositorios semilla php "$DIR/prueba_repositorios.php" "$BACKEND_W/Src"
correr deportistas semilla php "$DIR/prueba_http_deportistas.php" "$U"
correr deportistas_paso3 cuentas php "$DIR/prueba_http_deportistas_paso3.php" "$U"
correr auth semilla env SALTAR_RECUPERACION="${SALTAR_RECUPERACION:-}" php "$DIR/prueba_http_auth.php" "$U" "$BACKEND_W"
correr usuarios cuentas php "$DIR/prueba_http_usuarios.php" "$U"
correr documentos cuentas php "$DIR/prueba_http_documentos.php" "$U" "$BACKEND_W"
correr asistencia cuentas php "$DIR/prueba_http_asistencia.php" "$U"
correr calculo_pagos semilla php "$DIR/prueba_calculo_pagos.php" "$BACKEND_W/Src"
correr pagos cuentas php "$DIR/prueba_http_pagos.php" "$U"
correr calendario cuentas php "$DIR/prueba_http_calendario.php" "$U" "$U1M" "$BACKEND_W"
correr competencias cuentas php "$DIR/prueba_http_competencias.php" "$U"
correr recomendacion_peso semilla php "$DIR/prueba_recomendacion_peso.php" "$BACKEND_W/Src"
correr categorias_peso semilla php "$DIR/prueba_categorias_peso.php" "$BACKEND_W/Src"
correr reglas_evaluacion semilla php "$DIR/prueba_reglas_evaluacion.php" "$BACKEND_W/Src"
correr evaluaciones cuentas php "$DIR/prueba_http_evaluaciones.php" "$U"
correr estadisticas cuentas php "$DIR/prueba_http_estadisticas.php" "$U" "$BACKEND_W/Src"
correr reportes cuentas php "$DIR/prueba_http_reportes.php" "$U" "$BACKEND_W" "$MEMORIA"
correr autorregistro cuentas php "$DIR/prueba_http_autorregistro.php" "$U" "$BACKEND_W"
correr revision cuentas php "$DIR/prueba_http_revision.php" "$U" "$ULISTA" "$UAPACHE" "$BACKEND_W"
correr recuperacion_diferida cuentas php "$DIR/prueba_recuperacion_diferida.php" "$BACKEND_W/Src"
correr urls_limpias cuentas php "$DIR/prueba_http_urls_limpias.php" "$U" "$UAPACHE"
recargar cuentas
echo "TOTAL: $total comprobaciones OK"
