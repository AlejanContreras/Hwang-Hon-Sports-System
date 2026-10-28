#!/bin/bash
# Ejecuta todas las baterías del backend, recargando la base antes de cada una
# (cada batería espera la semilla recién cargada). Ver README.md.
#
# Variables opcionales (con sus valores por defecto):
#   URL_BASE=http://127.0.0.1:8090    servidor principal (router_pruebas.php)
#   URL_1M=http://127.0.0.1:8091      servidor con post_max_size=1M (calendario)
#   URL_LISTA=http://127.0.0.1:8092   servidor con ORIGENES_PERMITIDOS (revisión)
#   URL_APACHE=http://localhost/HwangHon-Sports-System/backend/Public   (revisión: logins paralelos)
#   MEMORIA_LOG=<carpeta>/memoria.log el mismo que se pasó al servidor principal (reportes)
#   MYSQL="mysql -uroot"              cliente de MariaDB
#   SALTAR_RECUPERACION=1             si Config/correo.php está en modo smtp (auth, sección F)
set -u
DIR="$(cd "$(dirname "$0")" && pwd)"
RAIZ="$(cd "$DIR/../.." && pwd)"
# En Windows (Git Bash) PHP necesita rutas C:/...: "pwd -W" las da; en Linux, pwd.
W="$(cd "$RAIZ" && (pwd -W 2>/dev/null || pwd))"
WD="$(cd "$DIR" && (pwd -W 2>/dev/null || pwd))"
U="${URL_BASE:-http://127.0.0.1:8090}"
U1M="${URL_1M:-http://127.0.0.1:8091}"
ULISTA="${URL_LISTA:-http://127.0.0.1:8092}"
UAPACHE="${URL_APACHE:-http://localhost/HwangHon-Sports-System/backend/Public}"
MEMORIA="${MEMORIA_LOG:-$WD/memoria.log}"
MYSQL="${MYSQL:-mysql -uroot}"
SALIDAS="$DIR/salidas"
mkdir -p "$SALIDAS"

recargar() { # $1 = "cuentas" para cargar también las cuentas de prueba locales
  $MYSQL -e "DROP DATABASE IF EXISTS hwanghon"
  $MYSQL --default-character-set=utf8mb4 < "$RAIZ/base_datos/esquema_hwanghon_v4.sql"
  $MYSQL --default-character-set=utf8mb4 hwanghon < "$RAIZ/base_datos/insercion_datos_prueba.sql"
  [ "${1:-}" = cuentas ] && $MYSQL --default-character-set=utf8mb4 hwanghon < "$RAIZ/base_datos/cuentas_prueba_locales.sql"
  rm -rf "$RAIZ/backend/Almacenamiento/sesiones"
  find "$RAIZ/backend/Almacenamiento/documentos" "$RAIZ/backend/Almacenamiento/generales" -type f -delete 2>/dev/null
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

correr repositorios semilla php "$DIR/prueba_repositorios.php" "$W/backend/Src"
correr deportistas semilla php "$DIR/prueba_http_deportistas.php" "$U"
correr deportistas_paso3 cuentas php "$DIR/prueba_http_deportistas_paso3.php" "$U"
correr auth semilla env SALTAR_RECUPERACION="${SALTAR_RECUPERACION:-}" php "$DIR/prueba_http_auth.php" "$U" "$W/backend"
correr usuarios cuentas php "$DIR/prueba_http_usuarios.php" "$U"
correr documentos cuentas php "$DIR/prueba_http_documentos.php" "$U" "$W/backend"
correr asistencia cuentas php "$DIR/prueba_http_asistencia.php" "$U"
correr calculo_pagos semilla php "$DIR/prueba_calculo_pagos.php" "$W/backend/Src"
correr pagos cuentas php "$DIR/prueba_http_pagos.php" "$U"
correr calendario cuentas php "$DIR/prueba_http_calendario.php" "$U" "$U1M" "$W/backend"
correr competencias cuentas php "$DIR/prueba_http_competencias.php" "$U"
correr recomendacion_peso semilla php "$DIR/prueba_recomendacion_peso.php" "$W/backend/Src"
correr categorias_peso semilla php "$DIR/prueba_categorias_peso.php" "$W/backend/Src"
correr reglas_evaluacion semilla php "$DIR/prueba_reglas_evaluacion.php" "$W/backend/Src"
correr evaluaciones cuentas php "$DIR/prueba_http_evaluaciones.php" "$U"
correr estadisticas cuentas php "$DIR/prueba_http_estadisticas.php" "$U" "$W/backend/Src"
correr reportes cuentas php "$DIR/prueba_http_reportes.php" "$U" "$W/backend" "$MEMORIA"
correr autorregistro cuentas php "$DIR/prueba_http_autorregistro.php" "$U" "$W/backend"
correr revision cuentas php "$DIR/prueba_http_revision.php" "$U" "$ULISTA" "$UAPACHE" "$W/backend"
correr recuperacion_diferida cuentas php "$DIR/prueba_recuperacion_diferida.php" "$W/backend/Src"
recargar cuentas
echo "TOTAL: $total comprobaciones OK"
