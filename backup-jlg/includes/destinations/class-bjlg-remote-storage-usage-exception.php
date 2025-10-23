<?php
namespace BJLG;

use RuntimeException;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exception dédiée aux erreurs de récupération des métriques de stockage distant.
 */
class BJLG_Remote_Storage_Usage_Exception extends RuntimeException
{
    /**
     * @var string
     */
    private $provider_code;

    /**
     * @var int|null
     */
    private $latency_ms;

    public function __construct(string $message, string $provider_code, ?int $latency_ms = null, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->provider_code = $provider_code;
        $this->latency_ms = $latency_ms;
    }

    public function get_provider_code(): string
    {
        return $this->provider_code;
    }

    public function get_latency_ms(): ?int
    {
        return $this->latency_ms;
    }
}
