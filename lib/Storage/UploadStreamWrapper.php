<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\Storage;

use Closure;

/**
 * PHP stream wrapper buffering writes in a seekable temp stream and executing
 * the Google Drive upload callback synchronously when fclose() runs.
 */
class UploadStreamWrapper {
    public const PROTOCOL = 'gdrivevolupload';

    /** @var resource|null */
    public $context;

    /** @var array<string, array{handle: resource, onClose: Closure}> */
    private static array $pending = [];

    /** @var resource|null */
    private $handle;

    private ?Closure $onClose = null;

    private bool $closed = false;

    /**
     * @param resource $tempHandle
     * @return resource|false
     */
    public static function open($tempHandle, Closure $onClose, string $mode = 'w+b') {
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $key = bin2hex(random_bytes(12));
        self::$pending[$key] = [
            'handle' => $tempHandle,
            'onClose' => $onClose,
        ];

        $resource = @fopen(self::PROTOCOL . '://' . $key, $mode);
        if ($resource === false) {
            unset(self::$pending[$key]);
        }
        return $resource;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
        $key = substr($path, strlen(self::PROTOCOL . '://'));
        if (!isset(self::$pending[$key])) {
            return false;
        }
        $this->handle = self::$pending[$key]['handle'];
        $this->onClose = self::$pending[$key]['onClose'];
        unset(self::$pending[$key]);
        $this->closed = false;
        return is_resource($this->handle);
    }

    public function stream_read(int $count): string|false {
        if (!is_resource($this->handle)) {
            return false;
        }
        return fread($this->handle, $count);
    }

    public function stream_write(string $data): int {
        if (!is_resource($this->handle)) {
            return 0;
        }
        $written = fwrite($this->handle, $data);
        return $written === false ? 0 : $written;
    }

    public function stream_tell(): int {
        if (!is_resource($this->handle)) {
            return 0;
        }
        $pos = ftell($this->handle);
        return $pos === false ? 0 : $pos;
    }

    public function stream_eof(): bool {
        if (!is_resource($this->handle)) {
            return true;
        }
        return feof($this->handle);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool {
        if (!is_resource($this->handle)) {
            return false;
        }
        return fseek($this->handle, $offset, $whence) === 0;
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false {
        if (!is_resource($this->handle)) {
            return false;
        }
        return fstat($this->handle);
    }

    public function stream_truncate(int $newSize): bool {
        if (!is_resource($this->handle)) {
            return false;
        }
        return ftruncate($this->handle, $newSize);
    }

    public function stream_flush(): bool {
        if (!is_resource($this->handle)) {
            return false;
        }
        return fflush($this->handle);
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool {
        return false;
    }

    public function stream_close(): void {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        try {
            if (is_resource($this->handle) && $this->onClose !== null) {
                fflush($this->handle);
                rewind($this->handle);
                ($this->onClose)($this->handle);
            }
        } finally {
            if (is_resource($this->handle)) {
                fclose($this->handle);
            }
            $this->handle = null;
            $this->onClose = null;
        }
    }
}
