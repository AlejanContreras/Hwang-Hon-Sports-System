<?php

declare(strict_types=1);

// Guarda de las secciones «Apache» (R-01, 31/10/2026). El Apache de XAMPP atiende con la base
// «hwanghon» (la de uso manual): estas baterías le mandarían logins fallidos (bloqueos de 15 min)
// y peticiones que escriben. Por defecto solo se acepta un servidor con puerto explícito (p. ej. el
// aislado, http://127.0.0.1:8095/backend/Public). Para verificar el mod_rewrite REAL a propósito:
// APACHE_REAL=1 (ver pruebas/backend/README.md).
function guardiaApache(string $url): void
{
    $partes = parse_url($url);
    $sinPuerto = is_array($partes) && isset($partes['host']) && !isset($partes['port']);
    if ($url === '' || !is_array($partes) || !isset($partes['host'])) {
        fwrite(STDERR, "Falta la URL de las secciones «Apache». Usa python pruebas/ejecutar_baterias_aisladas.py\n");
        exit(1);
    }
    if ($sinPuerto && getenv('APACHE_REAL') !== '1') {
        fwrite(STDERR, "Se niega a usar {$url}: parece el Apache de XAMPP (puerto 80), que usa la base «hwanghon».\n"
            . "Usa el servidor aislado (python pruebas/ejecutar_baterias_aisladas.py) o, a propósito, APACHE_REAL=1.\n");
        exit(1);
    }
}
