import json

with open(r'C:\Users\User\.gemini\antigravity\brain\cd9f4584-2946-41db-a6bb-af73cfc076d3\.system_generated\logs\transcript_full.jsonl', 'r', encoding='utf-8', errors='ignore') as f:
    for idx, line in enumerate(f):
        if idx == 648:
            data = json.loads(line)
            tool_calls = data.get('tool_calls', [])
            for tc in tool_calls:
                args = tc.get('args', {})
                if 'Replacement' in args:
                    print("=== REPLACEMENT ===")
                    print(args['Replacement'])
            break
