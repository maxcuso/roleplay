const path = require('node:path');
const fs = require('node:fs');
const webpack = require('webpack');
const base = require('flarum-webpack-config')();
const runtime = path.resolve(__dirname, '../tests/.runtime/preview-mithril');
// Берём ту же версию Mithril, которой пользуется установленный Flarum.
// Карта ядра уже используется клиентскими тестами; новые зависимости не устанавливаем.
const map = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../tests/.runtime/core-admin.js.map'), 'utf8'));
map.sources.forEach((name, index) => {
  const marker = '/mithril/';
  const start = name.indexOf(marker);
  if (start < 0 || !map.sourcesContent[index]) return;
  const relative = name.slice(start + marker.length);
  if (relative.includes('..') || !relative.endsWith('.js')) throw new Error('Unexpected Mithril source');
  const target = path.join(runtime, relative);
  fs.mkdirSync(path.dirname(target), {recursive: true});
  fs.writeFileSync(target, map.sourcesContent[index]);
});
const {loadLocalIcons} = require('./scripts/local-icons.cjs');
module.exports = {
  mode: 'development',
  entry: path.resolve(__dirname, 'preview/main.tsx'),
  output: {path: path.resolve(__dirname, '../tests/.runtime/preview-dist'), filename: 'preview.js'},
  resolve: {
    extensions: ['.tsx', '.ts', '.js', '.json'],
    alias: {
      'mithril$': path.join(runtime, 'index.js'),
      'flarum/common/Component$': path.resolve(__dirname, 'preview/Component.ts'),
      'flarum/common/app$': path.resolve(__dirname, 'preview/App.ts'),
      'flarum/forum/app$': path.resolve(__dirname, 'preview/App.ts'),
      'flarum/common/components/Alert$': path.resolve(__dirname, 'preview/Alert.ts'),
      'flarum/common/components/Icon$': path.resolve(__dirname, 'preview/Icon.ts'),
      'flarum/common/components/Button$': path.resolve(__dirname, 'preview/Button.ts'),
      'flarum/common/components/LinkButton$': path.resolve(__dirname, 'preview/LinkButton.ts'),
    },
  },
  // Только компилятор JSX/TypeScript. Регистрация модулей Flarum на стенде не нужна.
  module: {rules: base.module.rules.filter(rule => rule.loader.includes('babel-loader'))},
  plugins: [
    base.plugins[0],
    new webpack.ProvidePlugin({m: 'mithril'}),
    new webpack.DefinePlugin({ROLEPLAY_LOCAL_ICON_REPLACEMENTS: JSON.stringify(loadLocalIcons())}),
  ],
  devtool: 'source-map',
};
