# TerraChain C tooling
#
# Targets:
#   make            build all utilities
#   make test       run verification self-tests against known vectors
#   make clean      remove build artifacts

CC      ?= cc
CFLAGS  ?= -O2 -Wall -Wextra -std=c89
BUILD    = bin

all: $(BUILD)/chain-verify $(BUILD)/hmac

$(BUILD):
	mkdir -p $(BUILD)

$(BUILD)/chain-verify: integrity/chain.c | $(BUILD)
	$(CC) $(CFLAGS) -o $@ integrity/chain.c

$(BUILD)/hmac: crypto/hmac.c | $(BUILD)
	$(CC) $(CFLAGS) -o $@ crypto/hmac.c

.PHONY: test
test: $(BUILD)/chain-verify $(BUILD)/hmac
	@./c_tests/self_test.sh $(BUILD)/chain-verify
	@./c_tests/hmac_self_test.sh $(BUILD)/hmac

.PHONY: clean
clean:
	rm -rf $(BUILD)