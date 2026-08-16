/*
 * hmac.c — independent HMAC-SHA256 CLI (spec sections 5, 41).
 *
 * Usage:
 *   hmac <key> <data>            print lowercase hex signature of data
 *   hmac v <key> <sig> -- <data> verify; exit 0 on match, 1 on mismatch
 *
 * Built on the same self-contained SHA-256 implementation as
 * c/integrity/chain.c so hash results are independently reproducible.
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdint.h>

/* ---------------- SHA-256 (FIPS 180-4) ---------------- */

static const uint32_t K[64] = {
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
};

typedef struct {
    uint32_t h[8];
    uint64_t len;
    unsigned char buf[64];
    size_t buflen;
} SHA256_CTX_T;

static void sha256_block(SHA256_CTX_T *ctx, const unsigned char *block)
{
    uint32_t w[64];
    uint32_t a, b, c, d, e, f, g, h, t1, t2;
    int i;

    for (i = 0; i < 16; i++) {
        w[i] = ((uint32_t)block[i * 4] << 24) | ((uint32_t)block[i * 4 + 1] << 16)
             | ((uint32_t)block[i * 4 + 2] << 8) | (uint32_t)block[i * 4 + 3];
    }
    for (i = 16; i < 64; i++) {
        uint32_t s0 = ((w[i - 15] >> 7) | (w[i - 15] << 25)) ^ ((w[i - 15] >> 18) | (w[i - 15] << 14)) ^ (w[i - 15] >> 3);
        uint32_t s1 = ((w[i - 2] >> 17) | (w[i - 2] << 15)) ^ ((w[i - 2] >> 19) | (w[i - 2] << 13)) ^ (w[i - 2] >> 10);
        w[i] = w[i - 16] + s0 + w[i - 7] + s1;
    }
    a = ctx->h[0]; b = ctx->h[1]; c = ctx->h[2]; d = ctx->h[3];
    e = ctx->h[4]; f = ctx->h[5]; g = ctx->h[6]; h = ctx->h[7];
    for (i = 0; i < 64; i++) {
        uint32_t S1 = ((e >> 6) | (e << 26)) ^ ((e >> 11) | (e << 21)) ^ ((e >> 25) | (e << 7));
        uint32_t ch = (e & f) ^ (~e & g);
        uint32_t S0 = ((a >> 2) | (a << 30)) ^ ((a >> 13) | (a << 19)) ^ ((a >> 22) | (a << 10));
        uint32_t maj = (a & b) ^ (a & c) ^ (b & c);
        t1 = h + S1 + ch + K[i] + w[i];
        t2 = S0 + maj;
        h = g; g = f; f = e; e = d + t1;
        d = c; c = b; b = a; a = t1 + t2;
    }
    ctx->h[0] += a; ctx->h[1] += b; ctx->h[2] += c; ctx->h[3] += d;
    ctx->h[4] += e; ctx->h[5] += f; ctx->h[6] += g; ctx->h[7] += h;
}

static void sha256_init(SHA256_CTX_T *ctx)
{
    ctx->h[0] = 0x6a09e667; ctx->h[1] = 0xbb67ae85; ctx->h[2] = 0x3c6ef372; ctx->h[3] = 0xa54ff53a;
    ctx->h[4] = 0x510e527f; ctx->h[5] = 0x9b05688c; ctx->h[6] = 0x1f83d9ab; ctx->h[7] = 0x5be0cd19;
    ctx->len = 0;
    ctx->buflen = 0;
}

static void sha256_update(SHA256_CTX_T *ctx, const unsigned char *data, size_t len)
{
    ctx->len += len;
    while (len > 0) {
        size_t take = 64 - ctx->buflen;
        if (take > len) take = len;
        memcpy(ctx->buf + ctx->buflen, data, take);
        ctx->buflen += take;
        data += take;
        len -= take;
        if (ctx->buflen == 64) {
            sha256_block(ctx, ctx->buf);
            ctx->buflen = 0;
        }
    }
}

static void sha256_final(SHA256_CTX_T *ctx, unsigned char out[32])
{
    uint64_t bitlen = ctx->len * 8;
    unsigned char pad = 0x80;
    unsigned char lenbytes[8];
    int i;

    for (i = 0; i < 8; i++)
        lenbytes[i] = (unsigned char)(bitlen >> (56 - i * 8));

    sha256_update(ctx, &pad, 1);
    while (ctx->buflen != 56) {
        unsigned char zero = 0;
        sha256_update(ctx, &zero, 1);
    }
    sha256_update(ctx, lenbytes, 8);

    for (i = 0; i < 8; i++) {
        out[i * 4] = (unsigned char)(ctx->h[i] >> 24);
        out[i * 4 + 1] = (unsigned char)(ctx->h[i] >> 16);
        out[i * 4 + 2] = (unsigned char)(ctx->h[i] >> 8);
        out[i * 4 + 3] = (unsigned char)(ctx->h[i]);
    }
}

static void sha256_bytes(const unsigned char *data, size_t len, unsigned char out[32])
{
    SHA256_CTX_T ctx;
    sha256_init(&ctx);
    sha256_update(&ctx, data, len);
    sha256_final(&ctx, out);
}

/* ---------------- HMAC-SHA256 (RFC 2104) ---------------- */

static void hmac_sha256(const unsigned char *key, size_t keylen,
                        const unsigned char *data, size_t datalen,
                        unsigned char out[32])
{
    unsigned char ipad[64], opad[64];
    unsigned char kk[64];
    unsigned char inner[32];
    unsigned char kblock[64];
    SHA256_CTX_T ctx;
    size_t i;

    memset(kblock, 0, sizeof kblock);
    if (keylen > 64) {
        sha256_bytes(key, keylen, kk);
        memcpy(kblock, kk, 32);
    } else {
        memcpy(kblock, key, keylen);
    }
    for (i = 0; i < 64; i++) {
        ipad[i] = kblock[i] ^ 0x36;
        opad[i] = kblock[i] ^ 0x5c;
    }
    sha256_init(&ctx);
    sha256_update(&ctx, ipad, 64);
    sha256_update(&ctx, data, datalen);
    sha256_final(&ctx, inner);

    sha256_init(&ctx);
    sha256_update(&ctx, opad, 64);
    sha256_update(&ctx, inner, 32);
    sha256_final(&ctx, out);
}

/* ---------------- Helpers ---------------- */

static void hex(const unsigned char *in, size_t len, char *out)
{
    static const char digits[] = "0123456789abcdef";
    size_t i;
    for (i = 0; i < len; i++) {
        out[i * 2] = digits[in[i] >> 4];
        out[i * 2 + 1] = digits[in[i] & 0x0f];
    }
    out[len * 2] = '\0';
}

static int hex_to_bytes(const char *hexstr, unsigned char *out, size_t maxlen)
{
    size_t len = strlen(hexstr);
    size_t i;
    if (len % 2 != 0 || len / 2 > maxlen) return 0;
    for (i = 0; i < len / 2; i++) {
        unsigned hi, lo;
        char c = hexstr[i * 2];
        char d = hexstr[i * 2 + 1];
        if (c >= '0' && c <= '9') hi = (unsigned)(c - '0');
        else if (c >= 'a' && c <= 'f') hi = (unsigned)(c - 'a' + 10);
        else if (c >= 'A' && c <= 'F') hi = (unsigned)(c - 'A' + 10);
        else return 0;
        if (d >= '0' && d <= '9') lo = (unsigned)(d - '0');
        else if (d >= 'a' && d <= 'f') lo = (unsigned)(d - 'a' + 10);
        else if (d >= 'A' && d <= 'F') lo = (unsigned)(d - 'A' + 10);
        else return 0;
        out[i] = (unsigned char)((hi << 4) | lo);
    }
    return (int)(len / 2);
}

static int constant_time_eq(const unsigned char *a, const unsigned char *b, size_t len)
{
    unsigned char diff = 0;
    size_t i;
    for (i = 0; i < len; i++) diff |= a[i] ^ b[i];
    return diff == 0;
}

int main(int argc, char **argv)
{
    unsigned char sig[32];
    char hexout[65];

    if (argc < 3) {
        fprintf(stderr, "usage: %s <key> <data>\n"
                        "       %s v <key> <sig> -- <data>\n",
                argv[0], argv[0]);
        return 2;
    }

    if (strcmp(argv[1], "v") == 0) {
        /* verify: hmac v <key> <sig> -- <data> */
        if (argc < 6 || strcmp(argv[4], "--") != 0) {
            fprintf(stderr, "usage: %s v <key> <sig> -- <data>\n", argv[0]);
            return 2;
        }
        const char *key = argv[2];
        const char *hexsig = argv[3];
        const char *data = argv[5];
        unsigned char expect[32];
        int n = hex_to_bytes(hexsig, expect, sizeof expect);
        if (n != 32) {
            fprintf(stderr, "invalid signature hex\n");
            return 2;
        }
        hmac_sha256((const unsigned char *)key, strlen(key),
                    (const unsigned char *)data, strlen(data), sig);
        if (constant_time_eq(sig, expect, 32)) {
            hex(sig, 32, hexout);
            printf("VERIFIED %s\n", hexout);
            return 0;
        }
        hex(sig, 32, hexout);
        printf("FAILED expected %s got %s\n", hexsig, hexout);
        return 1;
    }

    /* sign: hmac <key> <data> */
    {
        const char *key = argv[1];
        const char *data = argv[2];
        hmac_sha256((const unsigned char *)key, strlen(key),
                    (const unsigned char *)data, strlen(data), sig);
        hex(sig, 32, hexout);
        printf("%s\n", hexout);
        return 0;
    }
}