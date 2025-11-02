var webpack = require('webpack');
var path = require('path');
var TerserPlugin = require('terser-webpack-plugin');
var isProd = process.env.NODE_ENV === 'production' || process.env.NODE_ENV === 'production-wip';
var mode = isProd ? 'production' : 'development';

module.exports = {
    entry: {
        site: './app/main.js'
    },
    mode: mode,
    devtool: isProd ? false : 'source-map',
    target: 'web',
    output: {
        path: path.resolve(__dirname, 'assets'),
        filename: (pathData) => {
            return pathData.chunk && pathData.chunk.name === 'site'
                ? 'form.min.js'
                : `form.${pathData.chunk && pathData.chunk.name ? pathData.chunk.name : 'chunk'}.js`;
        },
        chunkFilename: 'form.[name].js'
    },
    optimization: {
        minimize: isProd,
        minimizer: isProd ? [
            new TerserPlugin({
                parallel: true,
                extractComments: false,
                terserOptions: {
                    compress: {
                        drop_console: true
                    },
                    format: {
                        comments: false
                    }
                }
            })
        ] : [],
        splitChunks: {
            cacheGroups: {
                vendors: {
                    test: /[\\/]node_modules[\\/]/,
                    priority: 1,
                    name: 'vendor',
                    enforce: true,
                    chunks: 'all'
                }
            }
        }
    },
    plugins: [
        new webpack.ProvidePlugin({
            'fetch': 'imports-loader?this=>global!exports-loader?global.fetch!whatwg-fetch'
        })
    ],
    externals: {
        jquery: 'jQuery',
        'grav-form': 'GravForm'
    },
    module: {
        rules: [
            { enforce: 'pre', test: /\.json$/, loader: 'json-loader' },
            { enforce: 'pre', test: /\.js$/, loader: 'eslint-loader', exclude: /node_modules/ },
            {
                test: /\.css$/,
                use: [
                    'style-loader',
                    'css-loader'
                ]
            },
            {
                test: /\.js$/,
                loader: 'babel-loader',
                exclude: /node_modules/,
                options: {
                    presets: ['@babel/preset-env']
                }
            }
        ]
    }
};
