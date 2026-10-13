from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ProductListingFlow.php');s=p.read_text(encoding='utf-8').replace("Helpers::getTaxSystemType()['taxVats']->map", "collect(Helpers::getTaxSystemType()['taxVats'])->map")
s=s.replace("$q->where('name', 'like', '%'.addcslashes($search, '%_\\\\').'%');", """$matches=$this->fields->categories($d->store)->where('name','like','%'.addcslashes($search,'%_\\\\').'%')->get(['id','parent_id']);
            $ids=$matches->map(fn($c)=>$d->step==='subcategory_id'?$c->id:($c->parent_id?:$c->id))->all();
            $q->whereIn('id',$ids);""")
p.write_text(s,encoding='utf-8')
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ProductVisionService.php');s=p.read_text(encoding='utf-8');needle="            return array_replace($meta, ['status' => 'success'";s=s.replace(needle,"""            if($valid['category_id']!==null){
                $category=collect($categories)->firstWhere('id',$valid['category_id']);
                if($category['parent_id']){$valid['subcategory_id']=$valid['category_id'];$valid['category_id']=(int)$category['parent_id'];}
            }
"""+needle);p.write_text(s,encoding='utf-8')
