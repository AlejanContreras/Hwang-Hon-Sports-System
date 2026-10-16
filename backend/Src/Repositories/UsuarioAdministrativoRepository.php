<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `usuario_administrativo`.
 * Usuario con acceso al ámbito administrativo del sistema (RN-T.10/RN-T.11).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 *
 * Las credenciales (contrasena, token_recuperacion y su vencimiento) nunca
 * salen en las lecturas normales (listar, obtenerPorId): solo las lee
 * obtenerParaAutenticacion(), y solo las escribe actualizarCredenciales().
 * Así un Service no puede devolverlas por descuido ni borrarlas al guardar un
 * modelo leído sin ellas.
 */
class UsuarioAdministrativoRepository extends BaseRepository
{
    protected const TABLA = 'usuario_administrativo';

    /**
     * Columnas por las que listar()/contar() pueden filtrar (BaseRepository).
     * Sin contrasena ni token_recuperacion: no se filtra por credenciales.
     */
    protected const COLUMNAS = [
        'correo', 'nombre', 'rol', 'es_administrador_principal', 'activa', 'creado_por_correo',
    ];

    protected const ORDEN = 'nombre, correo';

    /**
     * Columnas que devuelven las lecturas normales: todas menos las credenciales.
     */
    protected const COLUMNAS_LECTURA = 'correo, nombre, rol, es_administrador_principal, activa, creado_por_correo';

    /**
     * Obtiene una fila de `usuario_administrativo` por su llave primaria `correo`, sin credenciales.
     */
    public function obtenerPorId(string|int $correo): ?array
    {
        return $this->consultarUno(
            'SELECT ' . self::COLUMNAS_LECTURA . ' FROM usuario_administrativo WHERE correo = :correo',
            ['correo' => $correo]
        );
    }

    /**
     * Fila completa, credenciales incluidas. Solo para el inicio de sesión y
     * la recuperación de contraseña (AuthService); nunca se devuelve tal cual
     * en una respuesta.
     */
    public function obtenerParaAutenticacion(string $correo): ?array
    {
        return $this->consultarUno(
            'SELECT * FROM usuario_administrativo WHERE correo = :correo',
            ['correo' => $correo]
        );
    }

    /**
     * Inserta una nueva fila en `usuario_administrativo` a partir de un UsuarioAdministrativoModel.
     */
    public function crear(UsuarioAdministrativoModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->insertar(
            'INSERT INTO usuario_administrativo (correo, contrasena, nombre, rol, es_administrador_principal, activa, creado_por_correo, token_recuperacion, token_recuperacion_expira) VALUES (:correo, :contrasena, :nombre, :rol, :es_administrador_principal, :activa, :creado_por_correo, :token_recuperacion, :token_recuperacion_expira)',
            $datos
        );
    }

    /**
     * Actualiza los datos de la cuenta (sin credenciales) a partir de un
     * UsuarioAdministrativoModel. El correo no se cambia aquí: se cambia con
     * el procedimiento cambiar_correo_administrador.
     */
    public function actualizar(UsuarioAdministrativoModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE usuario_administrativo SET nombre = :nombre, rol = :rol, es_administrador_principal = :es_administrador_principal, activa = :activa, creado_por_correo = :creado_por_correo WHERE correo = :correo',
            [
                'correo' => $datos['correo'],
                'nombre' => $datos['nombre'],
                'rol' => $datos['rol'],
                'es_administrador_principal' => $datos['es_administrador_principal'],
                'activa' => $datos['activa'],
                'creado_por_correo' => $datos['creado_por_correo'],
            ]
        );
    }

    /**
     * Actualiza solo las credenciales: hash de la contraseña y token de
     * recuperación con su vencimiento (RN-T.12).
     */
    public function actualizarCredenciales(UsuarioAdministrativoModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE usuario_administrativo SET contrasena = :contrasena, token_recuperacion = :token_recuperacion, token_recuperacion_expira = :token_recuperacion_expira WHERE correo = :correo',
            [
                'correo' => $datos['correo'],
                'contrasena' => $datos['contrasena'],
                'token_recuperacion' => $datos['token_recuperacion'],
                'token_recuperacion_expira' => $datos['token_recuperacion_expira'],
            ]
        );
    }

    /**
     * Elimina una fila de `usuario_administrativo` por su llave primaria `correo`.
     */
    public function eliminar(string|int $correo): bool
    {
        return $this->ejecutar(
            'DELETE FROM usuario_administrativo WHERE correo = :correo',
            ['correo' => $correo]
        );
    }
}
