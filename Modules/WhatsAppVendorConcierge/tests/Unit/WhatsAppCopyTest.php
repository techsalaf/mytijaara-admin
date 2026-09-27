<?php
namespace Modules\WhatsAppVendorConcierge\tests\Unit;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppCopy;
use PHPUnit\Framework\TestCase;
class WhatsAppCopyTest extends TestCase
{
    public function test_native_formatting_is_idempotent_and_preserves_links_and_code(): void
    {
        $input="## Dashboard\n\n**Shop details**\n- Name: Test\n\nhttps://example.com/login?token=a_b-c\n```\n**literal**\n```";
        $out=WhatsAppCopy::format($input);
        $this->assertStringStartsWith('💬 *Dashboard*',$out);
        $this->assertStringContainsString('*Shop details*',$out);
        $this->assertStringContainsString('• Name: Test',$out);
        $this->assertStringContainsString('https://example.com/login?token=a_b-c',$out);
        $this->assertStringContainsString("```\n**literal**\n```",$out);
        $this->assertSame($out,WhatsAppCopy::format($out));
    }
    public function test_existing_emoji_and_empty_reply_are_preserved(): void
    {
        $this->assertSame('',$out=WhatsAppCopy::format(''));
        $this->assertSame('📦 *Saved*',WhatsAppCopy::format('📦 **Saved**'));
    }
}
