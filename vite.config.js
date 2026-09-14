import { createAppConfig } from "@nextcloud/vite-config";
import { join, resolve } from "path";

export default createAppConfig(
  {
    main: resolve(join("src", "main.js")),
    modern: resolve(join("src", "new-main.js")),
    dashboard: resolve(join("src", "dashboard.js")),
  },
  {
    createEmptyCSSEntryPoints: true,
    extractLicenseInformation: true,
    thirdPartyLicense: false,
    config: {
      resolve: {
        dedupe: ['vue']
      }
    }
  }
);