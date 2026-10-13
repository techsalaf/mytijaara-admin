from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ProductListingFlow.php');s=p.read_text(encoding='utf-8').replace("$data=$locked->data;if", "$data=$locked->data;unset($data['image']);if")
s=s.replace("$lines[]=str_replace('_'", "$lines[]=str_replace('_'")
p.write_text(s,encoding='utf-8')
p=Path('Modules/WhatsAppVendorConcierge/app/Services/LaunchTaxonomyService.php');s=p.read_text(encoding='utf-8').replace("}return $rows;", "}if($apply && in_array('conflict',array_column($rows,'action'),true))throw new \\RuntimeException('Taxonomy conflict: no records committed. Inspect dry-run manifest.');return $rows;")
p.write_text(s,encoding='utf-8')
