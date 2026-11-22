import path from 'node:path';
import { fileURLToPath } from 'node:url';
import express from 'express';
import { ModuleManager, ModuleStateStore } from './src/core/moduleManager.js';
import { createAdminRouter } from './src/routes/adminRoutes.js';
import { createRestRouter } from './src/routes/restRoutes.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
// Support running either from project root via tsx or from dist/ via node
const rootDir = __dirname.endsWith(`${path.sep}dist`) ? path.resolve(__dirname, '..') : __dirname;

const app = express();
const port = Number(process.env.PORT) || 3000;

app.set('view engine', 'ejs');
app.set('views', path.join(rootDir, 'views'));

app.use(express.json());
app.use(express.urlencoded({ extended: true }));

app.use('/assets', express.static(path.join(rootDir, 'assets')));

const stateStore = new ModuleStateStore();
const moduleManager = new ModuleManager();
moduleManager.refresh(stateStore);

const restRouter = createRestRouter(moduleManager);
app.use('/neora/v1', restRouter);
app.use('/wp-json/neora/v1', restRouter);

app.use('/', createAdminRouter(moduleManager, stateStore));

app.use('/wp-json', (_req, res) => {
  res.status(501).json({ error: 'Not yet migrated' });
});

app.listen(port, '0.0.0.0', () => {
  console.log(`Neora Core server listening on http://0.0.0.0:${port}`);
});
