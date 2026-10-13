from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services/CoreAdapters/ProductMutationService.php');s=p.read_text(encoding='utf-8')
a="            'veg' => 'nullable|boolean',";s=s.replace(a,a+'''
            'unit_id' => 'nullable|integer|exists:units,id',
            'available_time_starts' => 'nullable|date_format:H:i:s',
            'available_time_ends' => 'nullable|date_format:H:i:s',
            'is_prescription_required' => 'nullable|boolean',
            'add_ons' => 'nullable|array',
            'add_ons.*' => ['integer', \\Illuminate\\Validation\\Rule::exists('add_ons','id')->where('store_id',$store->id)],
            'additional_media' => 'nullable|array|max:5',
            'additional_media.*' => 'integer|min:1',
''',1)
s=s.replace("$item->add_ons = json_encode([]);", "$item->add_ons = json_encode($store->module->module_type === 'food' ? ($validated['add_ons'] ?? []) : []);",1)
s=s.replace("$item->available_time_starts = '00:00:00';", "$item->available_time_starts = $validated['available_time_starts'] ?? '00:00:00';",1).replace("$item->available_time_ends = '23:59:59';", "$item->available_time_ends = $validated['available_time_ends'] ?? '23:59:59';\n            if (array_key_exists('unit_id',$validated)) $item->unit_id = $validated['unit_id'];\n            if (!empty($validated['additional_media'])) $item->images = array_map(fn($id)=>['img'=>app(ProductMedia::class)->publish($id,$vendorId),'storage'=>Helpers::getDisk()],$validated['additional_media']);",1)
s=s.replace('$details->is_prescription_required = 0;',"$details->is_prescription_required = $validated['is_prescription_required'] ?? 0;",1)
p.write_text(s,encoding='utf-8')
# Canonical host image default is def.png; no invented image asset.
data={
'grocery':{'Grains & Staples':['Rice','Beans & Pulses','Garri & Cassava','Flour & Semolina'], 'Fresh Food':['Vegetables','Fruit','Meat & Poultry','Fish & Seafood'],'Provisions':['Milk & Dairy','Breakfast & Cereals','Cooking Oils','Seasonings & Spices'],'Drinks':['Water','Juices','Soft Drinks'],'Household Essentials':['Laundry Supplies','Cleaning Supplies','Tissue & Paper']},
'ecommerce':{'Fashion':['Women\'s Clothing','Men\'s Clothing','Children\'s Clothing'],'Footwear':['Shoes','Sandals & Slippers'],'Bags & Accessories':['Handbags','Backpacks','Jewellery','Watches'],'Beauty & Personal Care':['Skin Care','Hair Care','Fragrances'],'Electronics':['Phones & Accessories','Audio & Earphones','Computers & Accessories'],'Home & Kitchen':['Cookware','Bedding','Home Appliances'],'Baby & Kids':['Baby Care','Toys']},
'food':{'Nigerian Meals':['Rice Meals','Swallow & Soups','Beans & Yam Meals'],'Grills & Fast Food':['Shawarma','Burgers & Sandwiches','Grilled Meat & Fish'],'Bakes & Snacks':['Bread','Pastries','Small Chops'],'Beverages':['Fresh Juices','Smoothies','Tea & Coffee']},
'pharmacy':{'Personal Health':['First Aid Supplies','Medical Devices','Oral Care'],'Wellness Essentials':['Vitamins & Supplements','Hygiene Products'],'Mother & Baby Care':['Baby Hygiene','Maternity Essentials']}}
import json
p=Path('Modules/WhatsAppVendorConcierge/resources/data');p.mkdir(exist_ok=True)
(p/'launch-taxonomy.json').write_text(json.dumps(data,indent=2),encoding='utf-8')
