<?php

namespace ApolloPHP\Http;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

class Stream implements StreamInterface
{
    private $resource;
    private ?int $size = null;
    private bool $seekable;
    private bool $readable;
    private bool $writable;
    
    public function __construct($resource = null)
    {
        if (\is_string($resource)) {
            $content = $resource;
            $resource = fopen('php://temp', 'r+');
            fwrite($resource, $content);
            rewind($resource);
        }
        
        if (is_resource($resource)) {
            $this->resource = $resource;
            $meta = stream_get_meta_data($this->resource);
            $this->seekable = $meta['seekable'];
            $this->readable = in_array($meta['mode'], ['r', 'r+', 'w+', 'a+', 'x+', 'c+']);
            $this->writable = in_array($meta['mode'], ['r+', 'w', 'w+', 'a', 'a+', 'x', 'x+', 'c', 'c+']);
        } else {
            throw new RuntimeException('Invalid stream provided');
        }
    }
    
    public function __destruct()
    {
        $this->close();
    }
    
    public function __toString(): string
    {
        try {
            if ($this->isSeekable()) {
                $this->seek(0);
            }
            return $this->getContents();
        } catch (\Exception $e) {
            return '';
        }
    }
    
    public function close(): void
    {
        if (isset($this->resource)) {
            fclose($this->resource);
        }
        
        $this->detach();
    }
    
    public function detach()
    {
        if (!isset($this->resource)) {
            return null;
        }
        
        $resource = $this->resource;
        unset($this->resource);
        
        $this->size = null;
        $this->seekable = false;
        $this->readable = false;
        $this->writable = false;
        
        return $resource;
    }
    
    public function getSize(): ?int
    {
        if ($this->size !== null) {
            return $this->size;
        }
        
        if (!isset($this->resource)) {
            return null;
        }
        
        $stats = fstat($this->resource);
        if (isset($stats['size'])) {
            $this->size = $stats['size'];
            return $this->size;
        }
        
        return null;
    }
    
    public function tell(): int
    {
        if (!isset($this->resource)) {
            throw new RuntimeException('No resource available');
        }
        
        $result = ftell($this->resource);
        if ($result === false) {
            throw new RuntimeException('Unable to determine stream position');
        }
        
        return $result;
    }
    
    public function eof(): bool
    {
        return !isset($this->resource) || feof($this->resource);
    }
    
    public function isSeekable(): bool
    {
        return $this->seekable;
    }
    
    public function seek($offset, $whence = SEEK_SET): void
    {
        if (!$this->isSeekable()) {
            throw new RuntimeException('Stream is not seekable');
        }
        
        if (fseek($this->resource, $offset, $whence) === -1) {
            throw new RuntimeException('Unable to seek to stream position');
        }
    }
    
    public function rewind(): void
    {
        $this->seek(0);
    }
    
    public function isWritable(): bool
    {
        return $this->writable;
    }
    
    public function write($string): int
    {
        if (!$this->isWritable()) {
            throw new RuntimeException('Stream is not writable');
        }
        
        $result = fwrite($this->resource, $string);
        if ($result === false) {
            throw new RuntimeException('Unable to write to stream');
        }
        
        $this->size = null;
        return $result;
    }
    
    public function isReadable(): bool
    {
        return $this->readable;
    }
    
    public function read($length): string
    {
        if (!$this->isReadable()) {
            throw new RuntimeException('Stream is not readable');
        }
        
        $result = fread($this->resource, $length);
        if ($result === false) {
            throw new RuntimeException('Unable to read from stream');
        }
        
        return $result;
    }
    
    public function getContents(): string
    {
        if (!isset($this->resource)) {
            throw new RuntimeException('No resource available');
        }
        
        $contents = stream_get_contents($this->resource);
        if ($contents === false) {
            throw new RuntimeException('Unable to read stream contents');
        }
        
        return $contents;
    }
    
    public function getMetadata($key = null)
    {
        if (!isset($this->resource)) {
            return $key ? null : [];
        }
        
        $meta = stream_get_meta_data($this->resource);
        
        if ($key === null) {
            return $meta;
        }
        
        return $meta[$key] ?? null;
    }
}