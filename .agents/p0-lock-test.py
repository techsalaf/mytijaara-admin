from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/tests/Hardening/InboundCheckpointTest.php');s=p.read_text();idx=s.rfind('\n}');s=s[:idx]+r'''
    public function test_busy_contact_releases_job_without_consuming_message_and_can_resume(): void {
        [$job,$gateway,$conversation]=$this->fixture();
        $gateway->shouldReceive('sendTextMessage')->once()->andReturn([]);
        $lock=\Illuminate\Support\Facades\Cache::lock('wa_inbound_contact_'.$conversation->contact_id,150);
        $this->assertTrue($lock->get());
        $queueJob=\Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queueJob->shouldReceive('release')->once()->with(15);
        $job->setJob($queueJob);
        $this->runJob($job,$gateway);
        $this->assertSame(0,$job->actions);
        $this->assertSame(0,WhatsAppMessage::count());
        $this->assertGreaterThan(now()->addMinutes(9)->timestamp,$job->retryUntil()->getTimestamp());
        $lock->release();
        $this->runJob($job,$gateway);
        $this->assertSame(1,$job->actions);
        $this->assertSame('completed',InboundReceipt::first()->phase);
    }
'''+s[idx:];p.write_text(s)
