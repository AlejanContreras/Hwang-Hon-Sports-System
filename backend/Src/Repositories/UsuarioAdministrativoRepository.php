<?php

declare(strict_types=1);

/**
 * Repositorio de la tabla `usuario_administrativo`.
 * Usuario con acceso al ámbito administrativo del sistema (RN-T.10/RN-T.11).
 * CRUD genérico únicamente — sin reglas de negocio (esas se implementan en Etapa 5,
 * en la capa de Services, según la Especificación de Lógica de Negocio).
 */
class UsuarioAdministrativoRepository extends BaseRepository
{
    protected const TABLA = 'usuario_administrativo';

    /**
     * Columnas por las que listar()/contar() pueden filtrar (BaseRepository).
     * Sin contrasena ni token_recuperacion: no se filtra por credenciales.
     */
    protected const COLUMNAS = [
        'correo', 'nombre', 'rol', 'es_administrador_principal', 'creado_por_correo',
    ];

    protected const ORDEN = 'nombre';

    /**
     * Obtiene una fila de `usuario_administrativo` por su llave primaria `correo`.
     */
    public function obtenerPorId(string|int $correo): ?array
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
            'INSERT INTO usuario_administrativo (correo, contrasena, nombre, rol, es_administrador_principal, creado_por_correo, token_recuperacion, token_recuperacion_expira) VALUES (:correo, :contrasena, :nombre, :rol, :es_administrador_principal, :creado_por_correo, :token_recuperacion, :token_recuperacion_expira)',
            $datos
        );
    }

    /**
     * Actualiza una fila existente de `usuario_administrativo` a partir de un UsuarioAdministrativoModel.
     */
    public function actualizar(UsuarioAdministrativoModel $modelo): bool
    {
        $datos = $modelo->toArray();
        return $this->ejecutar(
            'UPDATE usuario_administrativo SET contrasena = :contrasena, nombre = :nombre, rol = :rol, es_administrador_principal = :es_administrador_principal, creado_por_correo = :creado_por_correo, token_recuperacion = :token_recuperacion, token_recuperacion_expira = :token_recuperacion_expira WHERE correo = :correo',
            $datos
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
