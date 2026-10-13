from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Jobs/ProcessIncomingWhatsAppMessage.php');s=p.read_text();s=s.replace('    public int $timeout = 120;', '''    public int $timeout = 120;

    public function retryUntil(): \\DateTimeInterface
    {
        // Lock contention must not exhaust three attempts while another message is still running.
        return now()->addMinutes(10);
    }''').replace('$this->release(5);', '$this->release(15);');p.write_text(s)
