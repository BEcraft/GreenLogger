<?php declare(strict_types = 1);

namespace GreenLog;


use GreenLog\handler\LogHandler;

use ReflectionFunction;
use SplFileObject;
use Closure;


class Logger
{



    /**
     * Almacenamiento para los mensajes.
     * @var array
     */
    protected $records = [];


    /**
     * Archivo donde se guardarán los mensajes.
     * @var null | SplFileObject
     */
    protected $file = null;


    /** @var LogHandler[] */
    protected $logHandlers = [];


    /** @var Closure */
    protected $formatter;


    /** @var int */
    protected $logCounter = 1;


    /**
     * Frequencia con que se guardarán los mensajes.
     * @var int
     */
    protected $frequency = false;


    /** @var int */
    protected $lastRecordPosition = 0;


    /**
     * Banderas para el Logger.
     * @var int
     */
    protected $flags;


    /**
     * Banderas que se usarán al llamar el método "json_encode".
     * @var int
     */
    protected $jsonFlags = (JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);


    /** @var int */
    public const FLUSH_RECORDS_ON_SAVE    = 226;  # > Elimina todos los mensajes ya guardados.
    public const SHOW_CANCELLED_RECORDS   = 246;  # > Muestra cuando un LogHandler cancela un mensaje.
    public const SAVE_RECORDS_ON_DESTRUCT = 266;  # > Guarda los mensajes al destruir la clase.


    /**
     * Niveles para los mensajes.
     * @var int
     */
    public const LOG_INFO    = 1;
    public const LOG_WARNING = 2;
    public const LOG_ERROR   = 3;


    /**
     * Identidicadores de niveles con sus respectivo nombre.
     * @var array
     */
    public const LOG_LEVEL = [
        self::LOG_INFO    => "Info",      # > Información
        self::LOG_WARNING => "Warning",   # > Advertencia
        self::LOG_ERROR   => "Error"      # > Error
    ];



    public function __construct(?string $file = null, int $frequency = 0, ?Closure $formatter = null, int $flags = 0x00)
    {
        # > [hour: 00 00:00, level: Info] -> Formatter por defecto.
        $this->setFormatter($formatter ?? function (array $record): string {
            return sprintf("[hour: %s, level: %s] -> %s", gmdate("h i:s", $record["date"]), static::LOG_LEVEL[$record["level"]], $record["message"]);
        });

        $this->frequency = ($frequency < 1) ? false : $frequency;
        $this->flags     = $flags;

        $this->setLogFile($file);
    }



    /**
     * @param Closure
     *
     * @throws LogException
     */
    public static function validateFormatter(Closure $formatter): void
    {
        $function = new ReflectionFunction($formatter);
        $return   = (string) $function->getReturnType();

        if ($function->getNumberOfParameters() !== 1)
            throw new LogException("Formatter must have only one parameter.");

        if ($return !== "string")
            throw new LogException(sprintf("Formatter must return a string not %s.", $return));
    }



    /**
     * @param Closure $formatter
     */
    public function setFormatter(Closure $formatter): void
    {
        self::validateFormatter($formatter); $this->formatter = $formatter;
    }



    /**
     * Agrega una nueva bandera.
     *
     * @param int $flag
     */
    public function addFlag(int $flag, bool $json = false): void
    {
        if ($json)
            if ( ! ($this->jsonFlags & $flag) ) $this->jsonFlags |= $flag;
        else
            if ( ! ($this->flags & $flag) ) $this->flags |= $flag;
    }



    /**
     * Elimina cierta bandera.
     *
     * @param int $flag
     */
    public function removeFlag(int $flag, bool $json = false): void
    {
        if ($json)
            if (($this->jsonFlags & $flag)) $this->jsonFlags &= ~($flag);
        else
            if (($this->flags & $flag)) $this->flags &= ~($flag);
    }



    /**
     * @param  string $file
     *
     * @return bool
     *
     * @throws LogException
     */
    public function setLogFile(?string $file = null): bool
    {
        if ($file === null || $file === "")
        {
            # > Eliminar el modo escritura:
            $this->file = null; return true;
        }

        if ( ! ($this->isWriteable()) && ! ($this->flags & Logger::SAVE_RECORDS_ON_DESTRUCT) )
        {
            trigger_error("Is not possible to set a log file if saving frequence is disabled, please enable it first.", E_USER_WARNING); return false;
        }

        if (file_exists($file) && ! (is_writeable($file)) )
            throw new LogException(sprintf("Incompatible permissions found in file %s, try to reset them.", $file));

        return ($this->file = new SplFileObject($file, "a+")) !== null;
    }



    /**
     * @return bool
     */
    public function hasLogFile(): bool
    {
        return $this->file !== null;
    }



    /**
     * @param int $limit
     */
    public function save(int $limit = 0): void
    {
        if ($this->hasLogFile())
        {
            $counter = 0;

            foreach (array_slice($this->records, $this->lastRecordPosition) as $i => $record)
            {
                if ($this->file->fwrite(json_encode($record, $this->jsonFlags) . PHP_EOL) !== null)
                {
                    $this->records[$i]["saved"] = true; # > Marcado
                }

                if (++$counter === $limit) break;
            }

            $this->lastRecordPosition += $counter; $this->flushRecords();
        }
    }



    /**
     * Verífica si el registrador está en modo escritura.
     *
     * @return bool
     */
    public function isWriteable(): bool
    {
        return $this->frequency > 0;
    }



    /**
     * @return bool
     */
    public function flushRecords(): bool
    {
        if (($this->flags & Logger::FLUSH_RECORDS_ON_SAVE))
        {

            foreach ($this->records as $i => $record)
            {
                if (isset($record["saved"])) unset($this->records[$i]);
            }

            return true;
        }

        return false;
    }



    /**
     * @return array
     */
    public function getRecords(int $limit = 0, ?Closure $callback = null): array
    {
        $records = array_slice($this->records, $limit);

        if ( ! ($callback) ) return $records;

        return array_filter($records, $callback);
    }



    /**
     * @param array  $search
     * @param array  $rows
     * @param array  $records
     * @param int    $limit
     *
     * @return       array
     */
    public function search(array $search, array $rows, array $records = [], int $limit = 0)
    {
        $found   = [];
        $counter = 0;

        $records = ($records === []) ? $this->records : $records;
        $search  = array_filter(array_unique($search), function (&$i): string {
            return ($i = trim((string) $i)) !== "";
        });

        if ($search === []) return [];

        foreach ($records as $record)
        {
            if ( ! (is_array($record)) || $record === []) continue;

            foreach ($rows as $row)
            {
                foreach ($search as $i)
                {
                    if (isset($record[$row]) && stripos((string) $record[$row], $i) !== false) $found[$i][] = $record;
                }
            }

            if (++$counter === $limit) break;

        }

        return $found;
    }



    /**
     * @param LogHandler $handler
     *
     * @throws LogException
     */
    public function registerLogHandler(LogHandler $handler): void
    {

        if (isset($this->logHandlers[strtoupper($handler->getName())]))
            throw new LogException(sprintf("Could not register %s because it is already registered.", $handler->getName()));

        $this->logHandlers[strtoupper($handler->getName())] = $handler;
    }



    /**
     * @param string $name
     *
     * @return bool
     */
    public function unregisterLogHandler(string $name): bool
    {
        if (isset($this->logHandlers[strtoupper($name)]))
            unset($this->logHandlers[strtoupper($name)]);

        return true;
    }



    /**
     * Agrega un nuevo mensaje a la lista.
     *
     * @param string $message
     * @param array  $parameters
     * @param int    $level
     */
    public function addRecord(string $message, array $parameters, int $level = Logger::LOG_INFO): void
    {
        if (trim($message) === "") return;

        if ( ! (isset(static::LOG_LEVEL[$level])) )
        {
            trigger_error("Unknown log level $level, assigning level \"Warning\".", E_USER_WARNING); $level = Logger::LOG_WARNING;
        }

        $record = [
            "level"         => $level,
            "message"       => (($parameters === []) ? $message : vsprintf($message, $parameters)),
            "date"          => ($time = time()),
            "readable_date" => sprintf(date("m%\s-d%\s-Y%\s-h%\s-i%\s-s%\s", $time), "M", "D", "Y", "H", "MN", "S")
        ];

        foreach ($this->logHandlers as $handler)
        {

            if ($handler->process($record, $this->formatter) === false) continue;

            if (($this->flags & Logger::SHOW_CANCELLED_RECORDS))
            {
                trigger_error(sprintf("Record has been cancelled by %s, content: %s", $handler->getName(), $record["message"]), E_USER_NOTICE);
            }

            return;
        }

        $this->records[] = $record;


        if ($this->isWriteable() && ($this->logCounter++ % $this->frequency) === 0)
            $this->save();

    }



    public function info(string $message, array $parameters = []): void
    {
        $this->addRecord($message, $parameters, Logger::LOG_INFO);
    }



    public function warning(string $message, array $parameters = []): void
    {
        $this->addRecord($message, $parameters, Logger::LOG_WARNING);
    }



    public function error(string $message, array $parameters = []): void
    {
        $this->addRecord($message, $parameters, Logger::LOG_ERROR);
    }



    public function __destruct()
    {
        if (($this->flags & Logger::SAVE_RECORDS_ON_DESTRUCT)) $this->save();
    }



}
