/*
 * TerraChain C verification utility (spec sections 6, 31, 41).
 *
 * Independently re-verifies a hash-linked integrity chain exported by the
 * PHP IntegrityService (api/v1/integrity/export). Pure C89, dependency-free:
 * includes its own SHA-256 implementation so verification cannot silently
 * rely on the same code that produced the hashes.
 *
 * Usage:
 *   chain-verify <chain.json>
 *
 * Exit codes: 0 = chain VALID, 1 = chain INVALID, 2 = I/O or format error.
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

/* ---------------- SHA-256 (independent implementation) ---------------- */

typedef struct {
    unsigned char data[64];
    unsigned int datalen;
    unsigned long long bitlen;
    unsigned int state[8];
} Sha256Ctx;

static const unsigned int SHA256_K[64] = {
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1,
    0x923f82a4, 0xab1c5ed5, 0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3,
    0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174, 0xe49b69c1, 0xefbe4786,
    0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147,
    0x06ca6351, 0x14292967, 0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13,
    0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85, 0xa2bfe8a1, 0xa81a664b,
    0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a,
    0x5b9cca4f, 0x682e6ff3, 0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208,
    0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
};

static unsigned int rotr(unsigned int x, unsigned int n)
{
    return (x >> n) | (x << (32 - n));
}

static void sha256_transform(Sha256Ctx *ctx, const unsigned char data[64])
{
    unsigned int w[64], a, b, c, d, e, f, g, h, t1, t2;
    int i;

    for (i = 0; i < 16; i++) {
        w[i] = ((unsigned int)data[i * 4] << 24) |
               ((unsigned int)data[i * 4 + 1] << 16) |
               ((unsigned int)data[i * 4 + 2] << 8) |
               ((unsigned int)data[i * 4 + 3]);
    }
    for (; i < 64; i++) {
        unsigned int s0 = rotr(w[i - 15], 7) ^ rotr(w[i - 15], 18) ^ (w[i - 15] >> 3);
        unsigned int s1 = rotr(w[i - 2], 17) ^ rotr(w[i - 2], 19) ^ (w[i - 2] >> 10);
        w[i] = w[i - 16] + s0 + w[i - 7] + s1;
    }

    a = ctx->state[0]; b = ctx->state[1]; c = ctx->state[2]; d = ctx->state[3];
    e = ctx->state[4]; f = ctx->state[5]; g = ctx->state[6]; h = ctx->state[7];

    for (i = 0; i < 64; i++) {
        unsigned int S1 = rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25);
        unsigned int ch = (e & f) ^ (~e & g);
        unsigned int S0 = rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22);
        unsigned int maj = (a & b) ^ (a & c) ^ (b & c);
        t1 = h + S1 + ch + SHA256_K[i] + w[i];
        t2 = S0 + maj;
        h = g; g = f; f = e; e = d + t1;
        d = c; c = b; b = a; a = t1 + t2;
    }

    ctx->state[0] += a; ctx->state[1] += b; ctx->state[2] += c; ctx->state[3] += d;
    ctx->state[4] += e; ctx->state[5] += f; ctx->state[6] += g; ctx->state[7] += h;
}

static void sha256_init(Sha256Ctx *ctx)
{
    ctx->datalen = 0;
    ctx->bitlen = 0;
    ctx->state[0] = 0x6a09e667; ctx->state[1] = 0xbb67ae85;
    ctx->state[2] = 0x3c6ef372; ctx->state[3] = 0xa54ff53a;
    ctx->state[4] = 0x510e527f; ctx->state[5] = 0x9b05688c;
    ctx->state[6] = 0x1f83d9ab; ctx->state[7] = 0x5be0cd19;
}

static void sha256_update(Sha256Ctx *ctx, const unsigned char *data, unsigned int len)
{
    unsigned int i;

    for (i = 0; i < len; i++) {
        ctx->data[ctx->datalen] = data[i];
        ctx->datalen++;
        if (ctx->datalen == 64) {
            sha256_transform(ctx, ctx->data);
            ctx->bitlen += 512;
            ctx->datalen = 0;
        }
    }
}

static void sha256_final(Sha256Ctx *ctx, unsigned char hash[32])
{
    unsigned int i;
    unsigned long long bitlen = ctx->bitlen + ((unsigned long long)ctx->datalen * 8);

    ctx->data[ctx->datalen] = 0x80;
    ctx->datalen++;
    if (ctx->datalen > 56) {
        memset(ctx->data + ctx->datalen, 0, 64 - ctx->datalen);
        sha256_transform(ctx, ctx->data);
        ctx->datalen = 0;
    } else {
        memset(ctx->data + ctx->datalen, 0, 56 - ctx->datalen);
    }
    for (i = 0; i < 8; i++) {
        ctx->data[63 - i] = (unsigned char)(bitlen >> (i * 8));
    }
    sha256_transform(ctx, ctx->data);

    for (i = 0; i < 4; i++) {
        unsigned int shift = 24 - i * 8;
        hash[i]      = (unsigned char)(ctx->state[0] >> shift);
        hash[i + 4]  = (unsigned char)(ctx->state[1] >> shift);
        hash[i + 8]  = (unsigned char)(ctx->state[2] >> shift);
        hash[i + 12] = (unsigned char)(ctx->state[3] >> shift);
        hash[i + 16] = (unsigned char)(ctx->state[4] >> shift);
        hash[i + 20] = (unsigned char)(ctx->state[5] >> shift);
        hash[i + 24] = (unsigned char)(ctx->state[6] >> shift);
        hash[i + 28] = (unsigned char)(ctx->state[7] >> shift);
    }
}

static void sha256_hex(const char *data, unsigned int len, char out[65])
{
    static const char *hex = "0123456789abcdef";
    Sha256Ctx ctx;
    unsigned char hash[32];
    unsigned int i;

    sha256_init(&ctx);
    sha256_update(&ctx, (const unsigned char *)data, len);
    sha256_final(&ctx, hash);
    for (i = 0; i < 32; i++) {
        out[i * 2] = hex[(hash[i] >> 4) & 0xf];
        out[i * 2 + 1] = hex[hash[i] & 0xf];
    }
    out[64] = '\0';
}

/* ---------------- minimal JSON field extraction ---------------- */

/* Returns malloc'd value for the first occurrence of "key": in `json`
 * (quoted string or raw scalar), or NULL when absent. */
static char *json_get(const char *json, const char *key)
{
    char needle[128];
    const char *p, *start;

    snprintf(needle, sizeof(needle), "\"%s\":", key);
    p = strstr(json, needle);
    if (!p) {
        return NULL;
    }
    p += strlen(needle);
    while (*p == ' ' || *p == '\t' || *p == '\n' || *p == '\r') {
        p++;
    }
    if (*p == '"') {
        const char *end = strchr(p + 1, '"');
        char *out;
        if (!end) {
            return NULL;
        }
        out = (char *)malloc((size_t)(end - p));
        if (!out) {
            return NULL;
        }
        memcpy(out, p + 1, (size_t)(end - p - 1));
        out[end - p - 1] = '\0';
        return out;
    }
    start = p;
    while (*p && *p != ',' && *p != '}' && *p != '\n') {
        p++;
    }
    {
        char *out = (char *)malloc((size_t)(p - start) + 1);
        if (!out) {
            return NULL;
        }
        memcpy(out, start, (size_t)(p - start));
        out[p - start] = '\0';
        return out;
    }
}

/* ---------------- verification ---------------- */

int main(int argc, char **argv)
{
    FILE *fp;
    long fsize;
    char *buf = NULL;
    const char *entries_start;
    const char *cursor;
    char anchor[65];
    char *prev = NULL;
    unsigned long count = 0;
    unsigned int problems = 0;

    if (argc != 2) {
        fprintf(stderr, "usage: %s <chain.json>\n", argv[0]);
        return 2;
    }

    fp = fopen(argv[1], "rb");
    if (!fp) {
        fprintf(stderr, "error: cannot open %s\n", argv[1]);
        return 2;
    }
    fseek(fp, 0, SEEK_END);
    fsize = ftell(fp);
    fseek(fp, 0, SEEK_SET);
    if (fsize <= 0) {
        fprintf(stderr, "error: empty file\n");
        fclose(fp);
        return 2;
    }
    buf = (char *)malloc((size_t)fsize + 1);
    if (!buf) {
        fprintf(stderr, "error: out of memory\n");
        fclose(fp);
        return 2;
    }
    if (fread(buf, 1, (size_t)fsize, fp) != (size_t)fsize) {
        fprintf(stderr, "error: read failure\n");
        free(buf);
        fclose(fp);
        return 2;
    }
    fclose(fp);
    buf[fsize] = '\0';

    /* anchor_0 = sha256("GENESIS:" + chain) as in IntegrityService::append */
    {
        char *chain = json_get(buf, "chain");
        char genesis[512];

        if (!chain) {
            fprintf(stderr, "error: missing \"chain\" key\n");
            free(buf);
            return 2;
        }
        snprintf(genesis, sizeof(genesis), "GENESIS:%s", chain);
        sha256_hex(genesis, (unsigned int)strlen(genesis), anchor);
        fprintf(stdout, "chain: %s\n", chain);
        free(chain);
    }

    entries_start = strstr(buf, "\"entries\":");
    if (!entries_start) {
        fprintf(stderr, "error: missing \"entries\"\n");
        free(buf);
        return 2;
    }
    entries_start = strchr(entries_start, '[');
    if (!entries_start) {
        fprintf(stderr, "error: malformed \"entries\"\n");
        free(buf);
        return 2;
    }

    cursor = entries_start + 1;
    while (*cursor && *cursor != ']') {
        char *entry = NULL;
        const char *start, *close;
        size_t depth = 0;
        char *rid, *rtype, *rhash, *prev_hash, *anchor_hash;
        char expected[65], input[512];

        while (*cursor && *cursor != '{') {
            cursor++;
        }
        if (*cursor != '{') {
            break;
        }
        /* isolate the entry object (respect nesting to the matching '}') */
        start = cursor;
        close = start;
        do {
            if (*close == '{') {
                depth++;
            } else if (*close == '}') {
                depth--;
            }
            close++;
        } while (depth > 0 && *close);
        if (depth > 0) {
            fprintf(stderr, "ERROR unterminated entry object\n");
            problems++;
            break;
        }
        entry = (char *)malloc((size_t)(close - start) + 1);
        if (!entry) {
            fprintf(stderr, "error: out of memory\n");
            free(buf);
            return 2;
        }
        memcpy(entry, start, (size_t)(close - start));
        entry[close - start] = '\0';
        cursor = close;

        rid = json_get(entry, "record_id");
        rtype = json_get(entry, "record_type");
        rhash = json_get(entry, "record_hash");
        prev_hash = json_get(entry, "previous_hash");
        anchor_hash = json_get(entry, "anchor_hash");
        if (!rid || !rtype || !rhash || !anchor_hash) {
            fprintf(stderr, "ERROR malformed entry near %s#%s\n", rtype ? rtype : "?", rid ? rid : "?");
            problems++;
            free(entry);
            break;
        }
        count++;

        /* 1. link continuity: entry.previous_hash must equal prior record_hash;
           JSON null decodes to the literal string "null" here */
        if (prev_hash == NULL || prev_hash[0] == '\0' || strcmp(prev_hash, "null") == 0) {
            if (prev != NULL) {
                fprintf(stderr, "ERROR %s#%s: expected previous hash, got none (breaks chain)\n", rtype, rid);
                problems++;
            }
        } else if (prev == NULL) {
            fprintf(stderr, "ERROR %s#%s: chain start claims previous hash %s\n", rtype, rid, prev_hash);
            problems++;
        } else if (strcmp(prev_hash, prev) != 0) {
            fprintf(stderr, "ERROR %s#%s: broken link -- previous %s != expected %s\n",
                    rtype, rid, prev_hash, prev);
            problems++;
        }

        /* 2. anchor continuity: anchor_n = sha256(anchor_{n-1} . record_hash) */
        snprintf(input, sizeof(input), "%s%s", anchor, rhash);
        sha256_hex(input, (unsigned int)strlen(input), expected);
        if (strcmp(expected, anchor_hash) != 0) {
            fprintf(stderr, "ERROR %s#%s: anchor mismatch -- expected %s, stored %s\n",
                    rtype, rid, expected, anchor_hash);
            problems++;
        }

        /* advance state */
        if (prev) {
            free(prev);
        }
        {
            size_t rl = strlen(rhash) + 1;
            prev = (char *)malloc(rl);
            if (prev) {
                memcpy(prev, rhash, rl);
            }
        }
        {
            size_t al = strlen(anchor_hash) + 1;
            if (al > sizeof(anchor)) {
                al = sizeof(anchor);
            }
            memcpy(anchor, anchor_hash, al);
            anchor[sizeof(anchor) - 1] = '\0';
        }

        free(rid);
        free(rtype);
        free(rhash);
        free(prev_hash);
        free(anchor_hash);
        free(entry);
    }

    fprintf(stdout, "entries verified: %lu\n", count);
    if (problems == 0) {
        fprintf(stdout, "VERDICT: VALID\n");
    } else {
        fprintf(stdout, "VERDICT: INVALID (%u problem(s))\n", problems);
    }
    if (prev) {
        free(prev);
    }
    free(buf);
    return problems == 0 ? 0 : 1;
}