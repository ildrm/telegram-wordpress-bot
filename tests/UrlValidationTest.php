<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the SSRF guard used before the bot makes server-side requests to a
 * user-supplied site URL. Uses IP literals to avoid DNS lookups.
 */
class UrlValidationTest extends TestCase
{
    public function testAcceptsPublicHttpsIp(): void
    {
        $this->assertSame('https://93.184.216.34', tgwp_validate_site_url('https://93.184.216.34'));
    }

    public function testRejectsHttpScheme(): void
    {
        $this->assertNull(tgwp_validate_site_url('http://93.184.216.34'));
    }

    public function testRejectsPrivateIp(): void
    {
        $this->assertNull(tgwp_validate_site_url('https://192.168.1.10'));
        $this->assertNull(tgwp_validate_site_url('https://10.0.0.5'));
    }

    public function testRejectsLoopback(): void
    {
        $this->assertNull(tgwp_validate_site_url('https://127.0.0.1'));
    }

    public function testRejectsNonHttpsSchemes(): void
    {
        $this->assertNull(tgwp_validate_site_url('ftp://93.184.216.34'));
        $this->assertNull(tgwp_validate_site_url('file:///etc/passwd'));
    }

    public function testRejectsGarbage(): void
    {
        $this->assertNull(tgwp_validate_site_url('not a url'));
        $this->assertNull(tgwp_validate_site_url(''));
    }

    public function testTrimsWhitespace(): void
    {
        $this->assertSame('https://93.184.216.34', tgwp_validate_site_url('  https://93.184.216.34  '));
    }
}
