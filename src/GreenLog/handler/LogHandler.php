<?php declare(strict_types = 1);

namespace GreenLog\handler;


use Closure;


interface LogHandler
{



    /**
     * Consigue el nombre del handler.
     *
     * @return string
     */
    public function getName(): string;



    /**
     * @param array    $record
     * @param Closure  $formatter
     *
     * @return bool Devolver true si se quiere cancelar el mensaje.
     */
    public function process(array $record, Closure $formatter): bool;



}
