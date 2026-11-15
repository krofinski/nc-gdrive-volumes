<?php

declare(strict_types=1);

namespace OCA\GDriveVolumes\Storage;

/**
 * PHP stream wrapper providing directory iteration resources for virtual Google Drive folders.
 */
class DirectoryStreamWrapper {
    public const PROTOCOL = 'gdrivevoldir';

    /** @var resource|null */
    public $context;

    /** @var array<string, array<int, string>> */
    private static array $pending = [];

    /** @var array<int, string> */
    private array $entries = [];

    private int $cursor = 0;

    /**
     * Open a PHP directory handle backed by the provided list of entry names.
     *
     * @param array<int, string> $entries
     * @return resource|false
     */
    public static function open(array $entries) {
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $key = bin2hex(random_bytes(12));
        self::$pending[$key] = array_values($entries);
        $handle = @opendir(self::PROTOCOL . '://' . $key);
        if ($handle === false) {
            unset(self::$pending[$key]);
        }
        return $handle;
    }

    public function dir_opendir(string $path, int $options): bool {
        $key = substr($path, strlen(self::PROTOCOL . '://'));
        if (!isset(self::$pending[$key])) {
            return false;
        }
        $this->entries = self::$pending[$key];
        unset(self::$pending[$key]);
        $this->cursor = 0;
        return true;
    }

    public function dir_readdir(): string|false {
        if (!isset($this->entries[$this->cursor])) {
            return false;
        }
        return $this->entries[$this->cursor++];
    }

    public function dir_rewinddir(): bool {
        $this->cursor = 0;
        return true;
    }

    public function dir_closedir(): bool {
        $this->entries = [];
        $this->cursor = 0;
        return true;
    }
}
