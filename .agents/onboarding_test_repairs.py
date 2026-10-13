from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/tests/Unit/WhatsAppVendorConciergeTest.php')
s=p.read_text(encoding='utf-8')
s=s.replace("        $category = \\App\\Models\\Category::firstOrCreate(\n            ['name' => 'Demo category', 'parent_id' => 0],\n            ['status' => 1, 'position' => 0]\n        );", "        $category = \\App\\Models\\Category::forceCreate(['name' => 'Demo category', 'parent_id' => 0, 'status' => 1, 'position' => 0]);")
s=s.replace("            'file_name' => 'logo.png',\n", '')
s=s.replace('// Business category sub-list has 3 items', '// Business identity includes the optional cover photo.')
s=s.replace("return count($sections[0]['rows']) === 3;", "return count($sections[0]['rows']) === 4;")
s=s.replace("        $this->assertNotEmpty($session->collected_data['_pwd_token_hash']);\n        $this->assertNotEmpty($session->collected_data['_pwd_token_expires_at']);", """        $token = \\Modules\\WhatsAppVendorConcierge\\app\\Models\\CredentialToken::where('onboarding_session_id', $session->id)->firstOrFail();
        $this->assertNotEmpty($token->token_hash);
        $this->assertTrue($token->expires_at->isFuture());
        $this->assertArrayNotHasKey('_pwd_token_hash', $session->collected_data);""")
s=s.replace('    public function it_resolves_location_from_text_address()\n    {', '''    public function it_resolves_location_from_text_address()
    {
        \\Illuminate\\Support\\Facades\\DB::table('business_settings')->insert(['key' => 'map_api_key_server', 'value' => 'fake-maps-key']);
        \\Illuminate\\Support\\Facades\\Http::fake(['maps.googleapis.com/*' => \\Illuminate\\Support\\Facades\\Http::response([
            'status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => 6.52, 'lng' => 3.37]],
                'formatted_address' => '9A, Wing 1, Abiodun Fasakin Street, Lagos']],
        ])]);''')
for name in ['it_submits_application_with_null_vendor_status_for_pending_approval','it_handles_duplicate_vendor_phone_resiliently_on_submission']:
 start=s.index('    public function '+name+'()')
 end=s.index('\n    /** @test */',start)
 chunk=s[start:end]
 marker="        $conversation->update(['onboarding_session_id' => $session->id]);"
 chunk=chunk.replace(marker, marker+'\n        $this->completeSubmissionFixture($session);')
 s=s[:start]+chunk+s[end:]
helper='''
    private function completeSubmissionFixture(OnboardingSession $session): void
    {
        $module = \\App\\Models\\Module::create(['module_name' => 'Grocery', 'module_type' => 'grocery', 'status' => 1]);
        $zone = \\App\\Models\\Zone::create(['name' => 'Lagos', 'status' => 1]);
        \\Illuminate\\Support\\Facades\\DB::table('module_zone')->insert(['module_id' => $module->id, 'zone_id' => $zone->id]);
        $image = \\Illuminate\\Http\\UploadedFile::fake()->image('logo.png', 512, 512);
        $path = 'whatsapp/logos/test.png';
        \\Illuminate\\Support\\Facades\\Storage::disk('local')->put($path, file_get_contents($image->getRealPath()));
        $media = \\Modules\\WhatsAppVendorConcierge\\app\\Models\\WhatsAppMedia::create([
            'whatsapp_media_id' => 'logo-'.$session->id, 'mime_type' => 'image/png', 'status' => 'processed',
            'file_path' => $path, 'storage_disk' => 'local',
            'metadata' => ['contact_id' => $session->contact_id, 'purpose' => 'logo'],
        ]);
        $session->update(['collected_data' => array_merge($session->collected_data, [
            'module_id' => $module->id, 'zone_id' => $zone->id, 'logo_media_id' => $media->id,
            'privacy_accepted' => true, 'delivery_time' => '20-40 min',
        ])]);
    }
'''
s=s.rstrip()[:-1]+helper+'}\n'
p.write_text(s,encoding='utf-8')
