<?php

namespace Tests\Unit\Uploads;

use Apollo\Core\Uploads\Support\UploadConfig;
use Tests\TestCase;

class UploadConfigTest extends TestCase
{
    public function test_defaults(): void
    {
        $config = new UploadConfig([]);

        $this->assertSame('local', $config->driver());
        $this->assertSame('storage/uploads', $config->root());
        $this->assertSame('/uploads', $config->urlPrefix());
        $this->assertSame(10240, $config->maxSizeKb());
        $this->assertSame([], $config->allowedMimes());
        $this->assertFalse($config->overwrite());
    }

    public function test_reads_config_array(): void
    {
        $config = new UploadConfig([
            'driver' => 'local',
            'root' => '/srv/uploads',
            'url_prefix' => '/files',
            'max_size' => '2048',
            'allowed_mimes' => ['PNG', ' jpg ', 'pdf'],
            'overwrite' => true,
        ]);

        $this->assertSame('/srv/uploads', $config->root());
        $this->assertSame('/files', $config->urlPrefix());
        $this->assertSame(2048, $config->maxSizeKb());
        $this->assertSame(['png', 'jpg', 'pdf'], $config->allowedMimes());
        $this->assertTrue($config->overwrite());
    }

    public function test_allowed_mimes_accepts_csv_string(): void
    {
        $config = new UploadConfig(['allowed_mimes' => 'jpg,png,pdf']);

        $this->assertSame(['jpg', 'png', 'pdf'], $config->allowedMimes());

        $empty = new UploadConfig(['allowed_mimes' => '']);
        $this->assertSame([], $empty->allowedMimes());
    }

    public function test_all(): void
    {
        $config = new UploadConfig(['driver' => 'local']);

        $this->assertSame(['driver' => 'local'], $config->all());
    }
}