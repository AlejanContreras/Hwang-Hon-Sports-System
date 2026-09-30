<?php

declare(strict_types=1);

/**
 * Capa de logica de negocio del modulo Auth.
 * Modulo transversal de autenticacion (RN-T.10/RN-T.11). No tiene Model/Repository propio: usa UsuarioAdministrativoRepository y DeportistaRepository para validar credenciales de cada ambito.
 *
 * Los metodos de este archivo son firmas (contratos) definidas en la Etapa 4
 * (Diseno de arquitectura backend, fase "Contratos por modulo"); la
 * implementacion real de las reglas de negocio corresponde a la Etapa 5
 * (Desarrollo backend), segun la Especificacion de Logica de Negocio.
 *
 * Ubicacion: backend/Src/Services/AuthService.php
 */
class AuthService
{
    private UsuarioAdministrativoRepository $usuarioAdministrativo;
    private DeportistaRepository $deportista;

    public function __construct()
    {
        $this->usuarioAdministrativo = new UsuarioAdministrativoRepository();
        $this->deportista = new DeportistaRepository();
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function iniciarSesionAdministrativa(array $credenciales): array
    {
        throw new RuntimeException(
            'AuthService::iniciarSesionAdministrativa() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function iniciarSesionPublica(array $credenciales): array
    {
        throw new RuntimeException(
            'AuthService::iniciarSesionPublica() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function cerrarSesion(): void
    {
        throw new RuntimeException(
            'AuthService::cerrarSesion() pendiente de implementar en Etapa 5.'
        );
    }

    /**
     * TODO: implementar en Etapa 5 segun la Especificacion de Logica de Negocio.
     */
    public function sesionActual(): ?array
    {
        throw new RuntimeException(
            'AuthService::sesionActual() pendiente de implementar en Etapa 5.'
        );
    }
}
