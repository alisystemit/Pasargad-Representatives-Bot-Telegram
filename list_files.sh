#!/bin/bash
cd "$(dirname "$0")"
findstr /s /i /m "namespace" src/**/*.php > /tmp/ns.txt 2>&1
wc -l < /tmp/ns.txt
echo "---"
find src -type d | sort
echo "---"
find src -name "*.php" | sort | head -80
