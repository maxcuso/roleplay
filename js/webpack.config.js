const syncExtensionIcon = require('./scripts/sync-extension-icon.cjs');
const buildGuide = require('./scripts/build-guide.cjs');
const webpack = require('webpack');
const fs = require('node:fs');
const {localIconsPath, loadLocalIcons} = require('./scripts/local-icons.cjs');
const config = require('flarum-webpack-config')();

// Локальный файл не импортируется в исходники и не нужен на основном форуме.
// runtimeValue обновляет таблицу при изменении файла в режиме watch.
config.plugins.push(new webpack.DefinePlugin({
  ROLEPLAY_LOCAL_ICON_REPLACEMENTS: webpack.DefinePlugin.runtimeValue(
    () => JSON.stringify(loadLocalIcons()),
    {fileDependencies: [localIconsPath]}
  ),
}));

// Реестр Flarum 2 и очередь Webpack общие для всех расширений.
// Поэтому каждому нашему файлу нужен стабильный ID с префиксом Roleplay.
config.output = {
  ...config.output,
  uniqueName: 'maxcuso-roleplay',
  chunkLoadingGlobal: 'webpackChunkMaxcusoRoleplay',
  chunkFilename: '[name].js',
};
config.optimization = {...config.optimization, chunkIds: false};
config.plugins.push({
  apply(compiler) {
    // Значок пакета обновляется и при обычной сборке, и при работе наблюдателя.
    compiler.hooks.beforeRun.tap('RoleplayIconMetadata', syncExtensionIcon);
    compiler.hooks.beforeRun.tap('RoleplayGuide', buildGuide);
    compiler.hooks.watchRun.tap('RoleplayIconMetadata', syncExtensionIcon);
    compiler.hooks.watchRun.tap('RoleplayGuide', buildGuide);
    compiler.hooks.compilation.tap('RoleplayChunkIds', (compilation) => {
      for (const name of ['index.html', 'style.css']) {
        compilation.fileDependencies.add(require('node:path').resolve(__dirname, '../preview/role', name));
      }
      // Следим и за появлением файла, если наблюдатель запущен без локальных замен.
      if (!fs.existsSync(localIconsPath)) compilation.missingDependencies.add(localIconsPath);
      compilation.hooks.chunkIds.tap('RoleplayChunkIds', (chunks) => {
        for (const chunk of chunks) {
          if (!chunk.name) throw new Error('Roleplay async chunks must have stable names.');
          chunk.id = 'maxcuso-roleplay/' + chunk.name;
          chunk.ids = [chunk.id];
        }
      });
    });
  },
});

module.exports = config;
