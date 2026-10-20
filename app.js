// Startup file for cPanel "Application Manager" (Phusion Passenger).
// Set "Application startup file" to app.js. Passenger gives the port through the PORT variable (or its own socket);
// the standalone Next.js server (server.js, created by `next build`) reads PORT and HOSTNAME from the environment.
process.env.NODE_ENV = "production";
process.env.PORT = process.env.PORT || "3000";
process.env.HOSTNAME = process.env.HOSTNAME || "0.0.0.0";
// eslint-disable-next-line @typescript-eslint/no-require-imports
require("./server.js");
