<?php

namespace ApolloPHP\Http;

use Psr\Http\Message\UriInterface;

class Uri implements UriInterface
{
    private string $scheme = '';
    private string $host = '';
    private ?int $port = null;
    private string $path = '';
    private string $query = '';
    private string $fragment = '';
    private string $userInfo = '';
    
    public function __construct(string $uri = '')
    {
        if ($uri !== '') {
            $parts = parse_url($uri);
            
            if ($parts === false) {
                throw new \InvalidArgumentException("Unable to parse URI: $uri");
            }
            
            $this->scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
            $this->host = isset($parts['host']) ? strtolower($parts['host']) : '';
            $this->port = isset($parts['port']) ? $parts['port'] : null;
            $this->path = isset($parts['path']) ? $parts['path'] : '';
            $this->query = isset($parts['query']) ? $parts['query'] : '';
            $this->fragment = isset($parts['fragment']) ? $parts['fragment'] : '';
            $this->userInfo = isset($parts['user']) ? $parts['user'] : '';
            
            if (isset($parts['pass'])) {
                $this->userInfo .= ':' . $parts['pass'];
            }
        }
    }
    
    public static function createFromGlobals(): self
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $port = $_SERVER['SERVER_PORT'] ?? null;
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        $query = $_SERVER['QUERY_STRING'] ?? '';
        
        $uri = $scheme . '://' . $host;
        
        if ($port && (($scheme === 'http' && $port != 80) || ($scheme === 'https' && $port != 443))) {
            $uri .= ':' . $port;
        }
        
        $uri .= $path;
        
        if ($query) {
            $uri .= '?' . $query;
        }
        
        return new self($uri);
    }
    
    public function getScheme(): string
    {
        return $this->scheme;
    }
    
    public function getAuthority(): string
    {
        $authority = $this->host;
        
        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }
        
        if ($this->port !== null) {
            $authority .= ':' . $this->port;
        }
        
        return $authority;
    }
    
    public function getUserInfo(): string
    {
        return $this->userInfo;
    }
    
    public function getHost(): string
    {
        return $this->host;
    }
    
    public function getPort(): ?int
    {
        return $this->port;
    }
    
    public function getPath(): string
    {
        return $this->path;
    }
    
    public function getQuery(): string
    {
        return $this->query;
    }
    
    public function getFragment(): string
    {
        return $this->fragment;
    }
    
    public function withScheme($scheme): self
    {
        $scheme = strtolower($scheme);
        
        if ($this->scheme === $scheme) {
            return $this;
        }
        
        $new = clone $this;
        $new->scheme = $scheme;
        return $new;
    }
    
    public function withUserInfo($user, $password = null): self
    {
        $userInfo = $user;
        if ($password !== null) {
            $userInfo .= ':' . $password;
        }
        
        if ($this->userInfo === $userInfo) {
            return $this;
        }
        
        $new = clone $this;
        $new->userInfo = $userInfo;
        return $new;
    }
    
    public function withHost($host): self
    {
        $host = strtolower($host);
        
        if ($this->host === $host) {
            return $this;
        }
        
        $new = clone $this;
        $new->host = $host;
        return $new;
    }
    
    public function withPort($port): self
    {
        if ($this->port === $port) {
            return $this;
        }
        
        $new = clone $this;
        $new->port = $port;
        return $new;
    }
    
    public function withPath($path): self
    {
        if ($this->path === $path) {
            return $this;
        }
        
        $new = clone $this;
        $new->path = $path;
        return $new;
    }
    
    public function withQuery($query): self
    {
        if ($this->query === $query) {
            return $this;
        }
        
        $new = clone $this;
        $new->query = $query;
        return $new;
    }
    
    public function withFragment($fragment): self
    {
        if ($this->fragment === $fragment) {
            return $this;
        }
        
        $new = clone $this;
        $new->fragment = $fragment;
        return $new;
    }
    
    public function __toString(): string
    {
        $uri = '';
        
        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }
        
        $authority = $this->getAuthority();
        if ($authority !== '') {
            $uri .= '//' . $authority;
        }
        
        $uri .= $this->path;
        
        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }
        
        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }
        
        return $uri;
    }
}