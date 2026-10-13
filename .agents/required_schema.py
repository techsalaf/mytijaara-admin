from pathlib import Path
import re
s=Path('installation/backup/database.sql').read_text(encoding='utf-8')
for table in ['admins','vendors','modules','stores','users','items','admin_wallets','orders','order_details','delivery_men']:
 m=re.search(r'CREATE TABLE `'+table+r'`.*?;\s',s,re.S)
 print(table,[l.strip() for l in m.group().splitlines() if 'NOT NULL' in l and 'DEFAULT' not in l])
