#!/bin/sh
# Self-test for the C chain verifier: builds a small chain of records with
# known sha256 values and checks that the verifier accepts a good chain and
# rejects a tampered one.

BIN="$1"
[ -x "$BIN" ] || { echo "missing $BIN"; exit 2; }

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

GOOD="$TMP/good.json"
BAD="$TMP/bad.json"

cat > "$GOOD" <<'EOF'
{
  "chain": "selftest",
  "entries": [
    { "record_type": "rec", "record_id": "1", "record_hash": "aaa1111111111111111111111111111111111111111111111111111111111111", "previous_hash": null, "anchor_hash": "1531f9308b9aec372deb4911140981571bdbcabbb656c91e15f317e3f7afa57d", "created_at": "2026-08-16 00:00:00" },
    { "record_type": "rec", "record_id": "2", "record_hash": "bbb2222222222222222222222222222222222222222222222222222222222222", "previous_hash": "aaa1111111111111111111111111111111111111111111111111111111111111", "anchor_hash": "265a7b22be3918b02ff8ea3171548a689817f218932c419b5f94fcbf5c873db9", "created_at": "2026-08-16 00:00:01" }
  ],
  "count": 2
}
EOF

cp "$GOOD" "$BAD"
sed -i 's/"previous_hash": "aaa1111111111111111111111111111111111111111111111111111111111111"/"previous_hash": "ccc333333333333333333333333333333333333333333333333333333333333333"/' "$BAD"

FAILED=0

if "$BIN" "$GOOD" > "$TMP/out1" 2>&1; then
    grep -q "VERDICT: VALID" "$TMP/out1" && echo "PASS good chain accepted" || { echo "FAIL: good chain output missing VALID verdict"; cat "$TMP/out1"; FAILED=1; }
else
    echo "FAIL: good chain rejected"
    cat "$TMP/out1"
    FAILED=1
fi

if "$BIN" "$BAD" > "$TMP/out2" 2>&1; then
    echo "FAIL: tampered chain accepted (broken link must fail)"
    cat "$TMP/out2"
    FAILED=1
else
    grep -q "VERDICT: INVALID" "$TMP/out2" && echo "PASS tampered chain rejected" || { echo "FAIL: unexpected invalid output"; cat "$TMP/out2"; FAILED=1; }
fi

if [ "$FAILED" -eq 0 ]; then
    echo "ALL C SELF-TESTS PASSED"
    exit 0
fi
echo "C SELF-TESTS FAILED"
exit 1