.PHONY: test check package
test:
	php tests/run.php
	node tests/Browser/client-unit.mjs
check:
	python3 build/docs.py
	python3 build/check.py
package: test check
	python3 build/package.py
	python3 build/verify.py
