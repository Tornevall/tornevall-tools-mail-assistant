<?php
namespace MailSupportAssistant\Support;
use RuntimeException;
class RunLock
{
    private string $lockFile;
    /** @var resource|null */
    private $handle = null;
    private array $metadata = [];
    public function __construct(?string $lockFile = null)
    {
        $this->lockFile = $lockFile ?: ProjectPaths::runLockFile();
    }
    public function getLockFile(): string
    {
        return $this->lockFile;
    }
    public function acquire(string $owner = 'run'): array
    {
        ProjectPaths::ensureStorage();
        $handle = @fopen($this->lockFile, 'c+');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not open the run lock file: ' . $this->lockFile);
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            $metadata = $this->readMetadata($handle);
            @fclose($handle);
            return [
                'acquired' => false,
                'lock_file' => $this->lockFile,
                'metadata' => $metadata,
            ];
        }
        $this->handle = $handle;
        $this->metadata = [
            'owner' => trim($owner) !== '' ? trim($owner) : 'run',
            'pid' => function_exists('getmypid') ? (int) getmypid() : 0,
            'acquired_at' => date('c'),
            'host' => (string) php_uname('n'),
            'sapi' => (string) PHP_SAPI,
        ];
        $this->writeMetadata($handle, $this->metadata);
        return [
            'acquired' => true,
            'lock_file' => $this->lockFile,
            'metadata' => $this->metadata,
        ];
    }
    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }
        @ftruncate($this->handle, 0);
        @fflush($this->handle);
        @flock($this->handle, LOCK_UN);
        @fclose($this->handle);
        $this->handle = null;
        $this->metadata = [];
    }
    public function __destruct()
    {
        $this->release();
    }
    /**
     * @param resource $handle
     */
    private function readMetadata($handle): array
    {
        @rewind($handle);
        $contents = stream_get_contents($handle);
        $decoded = json_decode((string) $contents, true);
        return is_array($decoded) ? $decoded : [];
    }
    /**
     * @param resource $handle
     */
    private function writeMetadata($handle, array $metadata): void
    {
        @rewind($handle);
        @ftruncate($handle, 0);
        @fwrite($handle, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @fflush($handle);
    }
}
