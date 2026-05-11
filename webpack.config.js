const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
    ...defaultConfig,
    entry: {
        dashboard: path.resolve(__dirname, 'src/dashboard/index.js'),
        abilities: path.resolve(__dirname, 'src/abilities/index.js'),
        log:       path.resolve(__dirname, 'src/log/index.js'),
    },
};
