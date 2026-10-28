<?php

declare(strict_types=1);

// Las fechas (date(), 'today') se calculan con la hora de Bogotá, como el backend.
date_default_timezone_set('America/Bogota');

// Bordes de las tablas de peso (Junior y Juvenil OFICIALES, Fedecolhap v3.1 2024 art. 3 y 4; Mayores; Infantil PROPUESTA).
// Métodos estáticos puros. Uso: php prueba_categorias_peso.php <ruta backend/Src>
require $argv[1] . '/Services/DeportistaService.php';
require $argv[1] . '/Services/CompetenciaService.php';

$ok = 0; $fallos = [];
function comprobar(string $nombre, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;
    if ($condicion) { $ok++; echo "  OK   {$nombre}\n"; }
    else { $fallos[] = $nombre; echo "  FALLA {$nombre} {$detalle}\n"; }
}
$p = fn (?string $cat, string $sexo, float $peso) => DeportistaService::categoriaPorPeso($cat, $sexo, $peso);

echo "[cortes por grupo]\n";
$cortes = [
    ['Infantil', 'masculino', [20, 25, 30, 35]], ['Infantil', 'femenino', [20, 25, 30, 35]],
    ['Junior', 'masculino', [40, 45, 50, 55, 60]], ['Junior', 'femenino', [38, 43, 48, 53, 58]],
    ['Juvenil', 'masculino', [45, 50, 55, 60, 65, 70, 75]], ['Juvenil', 'femenino', [43, 48, 53, 58, 63, 68, 73]],
    ['Mayores', 'masculino', [55, 60, 65, 70, 75, 80, 85]], ['Mayores', 'femenino', [50, 55, 60, 65, 70, 75, 80]],
    ['Sénior', 'masculino', [55, 60, 65, 70, 75, 80, 85]], ['Máster', 'femenino', [50, 55, 60, 65, 70, 75, 80]],
];
foreach ($cortes as [$cat, $sexo, $esperados]) {
    comprobar("{$cat} {$sexo}: cortes " . implode(', ', $esperados), DeportistaService::cortesDePeso($cat, $sexo) === $esperados, json_encode(DeportistaService::cortesDePeso($cat, $sexo)));
}

echo "\n[bordes: en el corte, un poco por encima, por encima del último]\n";
foreach ($cortes as [$cat, $sexo, $c]) {
    if ($cat === 'Sénior' || $cat === 'Máster') {
        continue;
    }
    $primero = $c[0]; $segundo = $c[1]; $ultimo = end($c);
    comprobar("{$cat} {$sexo}: {$primero} kg exacto -> -{$primero} (inclusivo)", $p($cat, $sexo, (float) $primero) === "-{$primero}", (string) $p($cat, $sexo, (float) $primero));
    comprobar("{$cat} {$sexo}: " . ($primero + 0.01) . " kg -> -{$segundo}", $p($cat, $sexo, $primero + 0.01) === "-{$segundo}", (string) $p($cat, $sexo, $primero + 0.01));
    comprobar("{$cat} {$sexo}: {$ultimo} kg exacto -> -{$ultimo}", $p($cat, $sexo, (float) $ultimo) === "-{$ultimo}");
    comprobar("{$cat} {$sexo}: " . ($ultimo + 0.01) . " kg -> +{$ultimo}", $p($cat, $sexo, $ultimo + 0.01) === "+{$ultimo}", (string) $p($cat, $sexo, $ultimo + 0.01));
}
comprobar('Junior femenino 30 kg (debajo del primer corte) -> -38', $p('Junior', 'femenino', 30.0) === '-38');
comprobar('sin categoría por edad (menor de 8) o sin peso -> null', $p(null, 'masculino', 30.0) === null && $p('Junior', 'masculino', 0.0) === null);

echo "\n[fronteras de edad con el mismo peso]\n";
$referencia = '2026-10-23';
$categoria = function (string $nacimiento, string $sexo, float $peso) use ($referencia): array {
    $cat = DeportistaService::categoriaPorEdad(DeportistaService::edadEnFecha($nacimiento, $referencia));
    return [$cat, DeportistaService::categoriaPorPeso($cat, $sexo, $peso)];
};
comprobar('14 años (cumple 15 mañana), M 62 kg -> Junior +60', $categoria('2011-10-24', 'masculino', 62.0) === ['Junior', '+60'], json_encode($categoria('2011-10-24', 'masculino', 62.0)));
comprobar('15 años (cumple hoy), M 62 kg -> Juvenil -65', $categoria('2011-10-23', 'masculino', 62.0) === ['Juvenil', '-65'], json_encode($categoria('2011-10-23', 'masculino', 62.0)));
comprobar('14 años, F 40 kg -> Junior -43; 15 años, F 40 kg -> Juvenil -43', $categoria('2011-10-24', 'femenino', 40.0) === ['Junior', '-43'] && $categoria('2011-10-23', 'femenino', 40.0) === ['Juvenil', '-43']);
comprobar('17 años (cumple 18 mañana), M 47 kg -> Juvenil -50', $categoria('2008-10-24', 'masculino', 47.0) === ['Juvenil', '-50'], json_encode($categoria('2008-10-24', 'masculino', 47.0)));
comprobar('18 años (cumple hoy), M 47 kg -> Mayores -55', $categoria('2008-10-23', 'masculino', 47.0) === ['Mayores', '-55'], json_encode($categoria('2008-10-23', 'masculino', 47.0)));
comprobar('17 años, F 75 kg -> Juvenil +73; 18 años, F 75 kg -> Mayores -75', $categoria('2008-10-24', 'femenino', 75.0) === ['Juvenil', '+73'] && $categoria('2008-10-23', 'femenino', 75.0) === ['Mayores', '-75']);
comprobar('sugerirCategorias usa la edad a la fecha del torneo (14 -> 15 con 62 kg)', CompetenciaService::sugerirCategorias(['fecha_nacimiento' => '2011-10-24', 'sexo' => 'masculino', 'peso' => 62], '2026-10-23', 'combate_individual')['categoria_peso_sugerida'] === '+60'
    && CompetenciaService::sugerirCategorias(['fecha_nacimiento' => '2011-10-24', 'sexo' => 'masculino', 'peso' => 62], '2026-10-24', 'combate_individual')['categoria_peso_sugerida'] === '-65');

echo "\n[recomendacion_peso en la frontera Junior/Juvenil (margen 2,5 kg)]\n";
$r = fn (?string $cat, string $sexo, float $peso) => (string) CompetenciaService::recomendacionPeso($cat, $sexo, $peso);
comprobar('Junior M 41,5 kg: supera -40 por 1,5 -> recortar a -40', str_contains($r('Junior', 'masculino', 41.5), 'recortar para competir en -40'), $r('Junior', 'masculino', 41.5));
comprobar('Juvenil M 41,5 kg: no hay corte inferior (el primero es -45) -> null', CompetenciaService::recomendacionPeso('Juvenil', 'masculino', 41.5) === null);
comprobar('Junior M 62,5 kg: supera -60 por 2,5 (borde del margen) -> recortar a -60', str_contains($r('Junior', 'masculino', 62.5), 'recortar para competir en -60'), $r('Junior', 'masculino', 62.5));
comprobar('Juvenil M 62,5 kg: supera -60 por 2,5 -> recortar a -60', str_contains($r('Juvenil', 'masculino', 62.5), 'recortar para competir en -60'), $r('Juvenil', 'masculino', 62.5));
comprobar('Junior F 60,6 kg: supera -58 por 2,6 -> mantener', str_contains($r('Junior', 'femenino', 60.6), 'mantener'), $r('Junior', 'femenino', 60.6));
comprobar('Juvenil F 60,6 kg: supera -58 por 2,6 -> mantener', str_contains($r('Juvenil', 'femenino', 60.6), 'mantener'), $r('Juvenil', 'femenino', 60.6));
comprobar('Juvenil F 45 kg: supera -43 por 2 -> recortar a -43', str_contains($r('Juvenil', 'femenino', 45.0), 'recortar para competir en -43'), $r('Juvenil', 'femenino', 45.0));

echo "\n=== RESULTADO: {$ok} OK, " . count($fallos) . " fallos ===\n";
exit($fallos === [] ? 0 : 1);
