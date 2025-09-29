<?php

namespace ReportsModule\Exception;

/**
 * Кастомное исключение для модуля отчетов
 */
class ReportException extends \Exception
{
    /**
     * @param string $message Сообщение об ошибке
     * @param int $code Код ошибки
     * @param \Throwable|null $previous Предыдущее исключение
     */
    public function __construct(string $message = "", int $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        
        // Автоматически логируем ошибку
        $this->logError();
    }

    /**
     * Логирует ошибку
     * 
     * @return void
     */
    private function logError(): void
    {
        $logMessage = sprintf(
            "[ReportException] %s (Code: %d, File: %s:%d)",
            $this->getMessage(),
            $this->getCode(),
            basename($this->getFile()),
            $this->getLine()
        );
        
        error_log($logMessage);
    }
}