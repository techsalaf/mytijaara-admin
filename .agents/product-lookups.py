from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services/LaunchTaxonomyService.php');s=p.read_text(encoding='utf-8').replace('run(bool $apply=false,?int $moduleId=null)', 'run(bool $apply=false,?int $moduleId=null,bool $lookups=false)').replace('use($apply,$moduleId){','use($apply,$moduleId,$lookups){',1)
s=s.replace("['categories'=>DB::table('categories')->get()", "['units'=>DB::table('units')->get(),'attributes'=>DB::table('attributes')->get(),'categories'=>DB::table('categories')->get()")
s=s.replace('use($dataset,$apply,$moduleId){','use($dataset,$apply,$moduleId,$lookups){',1)
s=s.replace("    $rows=[];",'''    $rows=[];
    if($lookups)foreach(['units'=>['column'=>'unit','values'=>['Piece','Pack','Pair','Kilogram','Gram','Litre','Millilitre']], 'attributes'=>['column'=>'name','values'=>['Colour','Size','Material']]] as $table=>$lookup){
     foreach($lookup['values'] as $value){
      $existing=DB::table($table)->whereRaw('LOWER('.$lookup['column'].') = ?',[mb_strtolower($value)])->get();
      $action=$existing->count()>1?'conflict':($existing->count()===1?'matched':'created');$id=$existing->first()?->id;
      if($apply && $action==='created')$id=DB::table($table)->insertGetId([$lookup['column']=>$value,'created_at'=>now(),'updated_at'=>now()]);
      $rows[]=['kind'=>$table,'key'=>$table.'/'.Str::slug($value),'name'=>$value,'id'=>$id,'action'=>$action];
     }
    }''',1)
p.write_text(s,encoding='utf-8')
p=Path('Modules/WhatsAppVendorConcierge/app/Console/Commands/LaunchTaxonomyCommand.php');s=p.read_text(encoding='utf-8').replace('{--apply :','{--lookups : Include verified units and attribute names} {--apply :').replace("$this->option('module')?(int)$this->option('module'):null)", "$this->option('module')?(int)$this->option('module'):null,(bool)$this->option('lookups'))");p.write_text(s,encoding='utf-8')
