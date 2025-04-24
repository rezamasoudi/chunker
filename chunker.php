<?php

class Chunker
{
    const STATUS_INIT = 'init';
    const STATUS_RUNNING = 'running';
    const STATUS_FAIL = 'fail';
    const STATUS_FINISH = 'finish';

    protected string $chunk_id;
    protected string $work_dir;
    protected int $chunk_size = 100;
    protected array $meta = [];
    protected string $meta_path = "";
    protected ?Closure $done_callback = null;
    protected ?Closure $finish_callback = null;
    protected ?Closure $do_callback = null;
    protected ?Closure $fail_callback = null;
    protected string $except_message = '';
    protected bool $unwrap = false;
    protected int $chunk_position = 0;
    protected array $data = [];
    protected int $delay = 0; // delay in seconds between each chunk
    protected int $max_retries = 0;

    public function __construct(string $chunk_id)
    {
        $this->chunk_id = $chunk_id;
        $this->work_dir = __DIR__ . "/runtime/chunks";
    }

    public static function init(string $chunk_id): self
    {
        return new self($chunk_id);
    }

    public function id(): string
    {
        return $this->chunk_id;
    }

    public function workDir(string $work_dir): self
    {
        $this->work_dir = rtrim($work_dir, '/');
        $this->meta_path = "{$this->work_dir}/{$this->chunk_id}.json";

        $this->reloadMeta();
        $this->chunk_position = $this->meta['chunk_position'] ?? 0;

        return $this;
    }

    public function chunkSize(int $chunk_size): self
    {
        $this->chunk_size = $chunk_size;
        return $this;
    }

    public function delay(int $seconds): self
    {
        $this->delay = $seconds;
        return $this;
    }

    public function maxRetries(int $retries): self
    {
        $this->max_retries = $retries;
        return $this;
    }

    public function done(Closure $callback): self
    {
        $this->done_callback = $callback;
        return $this;
    }
    public function finsih(Closure $callback): self
    {
        $this->finish_callback = $callback;
        return $this;
    }

    public function fail(Closure $callback): self
    {
        $this->fail_callback = $callback;
        return $this;
    }

    public function except(string $msg): self
    {
        $this->except_message = $msg;
        return $this;
    }

    public function unwrap(bool $unwrap = true): self
    {
        $this->unwrap = $unwrap;
        return $this;
    }

    public function data(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function do(Closure $do_callback): self
    {
        $this->do_callback = $do_callback;
        return $this;
    }

    public function start(): void
    {
        $this->reloadMeta();

        // If already completed or no data exists, immediately trigger done callback
        if ($this->meta['status'] === self::STATUS_FINISH || count($this->data) === 0) {
            $this->triggerCallback($this->finish_callback);
            return;
        }

        // Calculate how many chunks we need to process
        $total_chunks = ceil(count($this->data) / $this->chunk_size);

        // Set status to running
        $this->writeMeta('status', self::STATUS_RUNNING);

        // Main loop to process each chunk
        while ($this->meta['status'] === self::STATUS_RUNNING) {

            // If all chunks are done, mark as completed and trigger done callback
            if ($this->chunk_position >= $total_chunks) {
                $this->writeMeta('status', self::STATUS_FINISH);
                $this->triggerCallback($this->finish_callback);
                break;
            }

            // Calculate start index and get the next chunk of data
            $start = $this->chunk_position * $this->chunk_size;
            $slice = array_slice($this->data, $start, $this->chunk_size);

            // In case the slice is empty (shouldn't happen normally), mark as done
            if (empty($slice)) {
                $this->writeMeta('status', self::STATUS_FINISH);
                $this->triggerCallback($this->finish_callback);
                break;
            }

            $attempt = 0;
            while ($attempt <= $this->max_retries) {
                $done = call_user_func_array($this->do_callback, [$slice, $this->meta]);

                if ($done) {

                    // chunk done callback
                    $this->triggerCallback($this->done_callback, [$this->meta]);

                    $this->chunk_position++;
                    $this->writeMeta('chunk_position', $this->chunk_position);
                    $this->sleep(); // delay before next chunk
                    continue 2;
                }

                $attempt++;
                $this->sleep(); // delay before retry
            }

            // If failed, update status and either throw or call fail callback
            $this->writeMeta('status', self::STATUS_FAIL);

            if ($this->unwrap) {
                throw new Exception($this->except_message ?? "Failed at chunk #{$this->chunk_position}");
            }

            $this->triggerCallback($this->fail_callback, [$this->meta]);
            break;
        }
    }

    protected function sleep(): void
    {
        if ($this->delay > 0) {
            sleep($this->delay);
        }
    }

    protected function getCurrentChunk(): array
    {
        $start = $this->chunk_position * $this->chunk_size;
        $length = min($this->chunk_size, count($this->data) - $start);
        return array_slice($this->data, $start, $length);
    }

    protected function isFinished(): bool
    {
        return ($this->meta['status'] ?? self::STATUS_INIT) === self::STATUS_FINISH;
    }

    protected function triggerCallback(?Closure $callback, array $args = []): void
    {
        if ($callback) {
            call_user_func_array($callback, $args);
        }
    }

    protected function writeMeta(string $key, string $value): void
    {
        if (!is_dir($this->work_dir)) {
            mkdir($this->work_dir, recursive: true);
        }

        $this->meta = $this->loadMeta();
        $this->meta[$key] = $value;

        file_put_contents(
            $this->meta_path,
            json_encode($this->meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    protected function reloadMeta(): void
    {
        if (!file_exists($this->work_dir)) {
            mkdir($this->work_dir, recursive: true);
        }

        $this->meta = $this->loadMeta();
    }

    protected function loadMeta(): array
    {
        $init_data = [
            'chunk_id' => $this->chunk_id,
            'status' => self::STATUS_INIT,
            'chunk_position' => 0
        ];

        if (!file_exists($this->meta_path)) {
            return $init_data;
        }

        $content = file_get_contents($this->meta_path);

        if (!is_string($content) || !json_validate($content)) {
            return $init_data;
        }

        return json_decode($content, true);
    }
}