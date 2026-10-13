from pathlib import Path
import re
s=Path('installation/backup/database.sql').read_text(encoding='utf-8')
tables=re.findall(r'CREATE TABLE `([^`]+)`',s)
print('Tables:', ', '.join(x for x in tables if any(y in x for y in ['order','wallet','subscription','tax','cashback','expense'])))
for table in ['orders','order_details','items','store_subscriptions']:
 m=re.search(r'CREATE TABLE `'+table+r'`.*?;\s',s,re.S)
 print(table, re.findall(r'^  `([^`]+)`',m.group() if m else '',re.M))
print('DDL count',len(re.findall(r'^(?:CREATE TABLE|ALTER TABLE).*?;\s',s,re.S|re.M)))
