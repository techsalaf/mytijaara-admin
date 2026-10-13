from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ProductFieldMap.php');s=p.read_text(encoding='utf-8').replace("$data['food_group_count','extra_details','tags','brand_id','organic','nutritions','allergies','condition_id','generic_name','basic','manufacturer','unit_value']", "$data['food_group_count']");p.write_text(s,encoding='utf-8')
