import re

with open(r'C:\Users\DELL\OneDrive\Desktop\CRM\HR_Recruitment_Module_PRD.pdf', 'rb') as f:
    data = f.read()

# Extract text from BT/ET blocks
text_parts = []
for m in re.finditer(rb'BT(.+?)ET', data, re.DOTALL):
    block = m.group(1)
    for tj in re.finditer(rb'\((.+?)\)\s*Tj', block):
        try:
            t = tj.group(1).decode('latin-1', errors='ignore')
            t = t.replace('\\n', '\n').replace('\\r', '').replace('\\t', ' ')
            if len(t.strip()) > 0:
                text_parts.append(t)
        except:
            pass
    for tj in re.finditer(rb'\[(.+?)\]\s*TJ', block):
        seg = []
        for inner in re.finditer(rb'\((.+?)\)', tj.group(1)):
            try:
                seg.append(inner.group(1).decode('latin-1', errors='ignore'))
            except:
                pass
        if seg:
            text_parts.append(''.join(seg))

text = '\n'.join(text_parts)
text = re.sub(r'[ \t]+', ' ', text)
text = re.sub(r'\n{3,}', '\n\n', text)

with open(r'C:\Users\DELL\OneDrive\Desktop\CRM\prd_text.txt', 'w', encoding='utf-8') as f:
    f.write(text)

print(f'Done: {len(text)} chars, {len(text_parts)} segments')
