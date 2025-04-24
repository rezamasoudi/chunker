# Chunker

**Chunker** is a lightweight PHP library designed to process large datasets in manageable chunks. It supports customizable callbacks, delays between chunks, and retry mechanisms for failed chunks.

---

## Features

- Easy initialization and configuration
- Custom `onDone`, `onFail`, and `onFinish` callbacks
- Automatic chunking of large datasets
- Retry logic with delay
- Persistent tracking via work directory

## Example Usage

```php
$data = range(1, 170000);

Chunker::init('test_01')
    ->workDir(__DIR__ . '/batches')         // Directory to store progress and metadata
    ->data($data)                           // Full dataset to be processed
    ->chunkSize(200)                        // Number of items per chunk
    ->delay(2)                              // Delay (in seconds) between chunks
    ->maxRetries(1)                         // Retry once on failure
    ->done(function (array $meta) {
        print ('Chunk #' . $meta['chunk_position'] . ' done successfully.' . PHP_EOL);
    })
    ->fail(function (array $meta) {
        var_dump($meta); // Log failure info
    })
    ->do(function (array $data, array $meta) {
        var_dump($data); // Your processing logic here
        return true;        // Return true to move next chunk or return false to retry current chunk
    })
    ->finsih(function () {
        print ('batch finished' . PHP_EOL); // Called once all chunks are processed
    })
    ->start();       
```

## Work Directory
Make sure the directory you specify in workDir() is writable. It stores metadata like progress and retries.


## License
MIT License — use it freely and contribute if you like!


