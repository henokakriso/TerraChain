#!/usr/bin/env bash
# HMAC-SHA256 self-test for c/bin/hmac.
# Vectors computed with an independent reference implementation
# (PHP hash_hmac); first two also match RFC 4231 test vectors.
set -u

BIN="${1:-bin/hmac}"
FAILED=0

check_sig() {
    local desc="$1" expected="$2" key="$3" data="$4"
    local got
    got=$("$BIN" "$key" "$data")
    if [ "$got" = "$expected" ]; then
        echo "PASS hmac signature ($desc)"
    else
        echo "FAIL hmac signature ($desc): expected $expected got $got"
        FAILED=1
    fi
}

check_verify() {
    local desc="$1" key="$2" sig="$3" data="$4" expect_code="$5"
    "$BIN" v "$key" "$sig" -- "$data" > /dev/null 2>&1
    local code=$?
    if [ "$code" -eq "$expect_code" ]; then
        echo "PASS hmac verify ($desc) exit=$code"
    else
        echo "FAIL hmac verify ($desc): expected exit $expect_code got $code"
        FAILED=1
    fi
}

KEY_RFC=$(printf '\x0b%.0s' {1..20})
check_sig "RFC 4231 #1" \
    "b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7" \
    "$KEY_RFC" "Hi There"
check_sig "RFC 4231 #2" \
    "5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843" \
    "Jefe" "what do ya want for nothing?"
check_sig "long data (> 64 bytes)" \
    "679d9727f7b27a0a3a16aa130fdf450fe55b16c7ee8f1fcab3d6d9bd4a93ee2c" \
    "shortkey" "$(printf 'x%.0s' {1..100})"
check_sig "long key (> 64 bytes)" \
    "42851c7dd19bcc407b0130bb63d4a5df035cb77a1c571666fed612fbfce889c4" \
    "$(printf 'q%.0s' {1..100})" "test data"
check_sig "boundary key length (65)" \
    "7281eb2fd0c6cd8cd3c0cf1e3a138e577d86891699609a1d4cd39a2a136d1b03" \
    "$(printf 'a%.0s' {1..65})" "boundary key length"

check_verify "good signature accepted" "Jefe" \
    "5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843" \
    "what do ya want for nothing?" 0
check_verify "tampered data rejected" "Jefe" \
    "5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843" \
    "what do ya want for nothing?!" 1
check_verify "wrong key rejected" "wrongkey" \
    "5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843" \
    "what do ya want for nothing?" 1
check_verify "bad hex rejected" "Jefe" "zzz" "data" 2

if [ "$FAILED" -eq 0 ]; then
    echo "ALL HMAC SELF-TESTS PASSED"
    exit 0
fi
echo "HMAC SELF-TESTS FAILED"
exit 1