APP_NAME = gdrive_volumes
BUILD_DIR = build
RELEASE_ARCHIVE = $(BUILD_DIR)/$(APP_NAME).tar.gz

.PHONY: clean package

clean:
	rm -rf $(BUILD_DIR)

package: clean
	mkdir -p $(BUILD_DIR)/$(APP_NAME)
	cp -R appinfo css img js lib templates composer.json COPYING README.md $(BUILD_DIR)/$(APP_NAME)/
	tar -czf $(RELEASE_ARCHIVE) -C $(BUILD_DIR) $(APP_NAME)
	@echo "Built $(RELEASE_ARCHIVE)"
