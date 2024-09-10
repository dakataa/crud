const Encore = require('@symfony/webpack-encore');
const Webpack = require('webpack');
const CssMinimizerPlugin = require('css-minimizer-webpack-plugin');
const SpriteLoaderPlugin = require('svg-sprite-loader/plugin');

const ASSET_OUTPUT_PATH = 'public/assets/';
const ASSET_PUBLIC_PATH = '/bundles/dakataacrud/assets';

if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
}

Encore
    .setOutputPath(ASSET_OUTPUT_PATH)
    .setPublicPath(ASSET_PUBLIC_PATH)
	.setManifestKeyPrefix('bundles/dakataacrud')
    .cleanupOutputBeforeBuild()
    .enableLessLoader()
    .disableSingleRuntimeChunk()
    .configureCssMinimizerPlugin((config) => {
        config.parallel = true;
        config.minify = [
            CssMinimizerPlugin.cssnanoMinify,
            CssMinimizerPlugin.cleanCssMinify,
        ];
        config.minimizerOptions = {
            preset: [
                'default',
                {
                    discardComments: {removeAll: true},
                },
            ],
        };
    })
    .configureTerserPlugin(config => {
        config.parallel = true;
        config.extractComments = false;
        config.terserOptions = {
            mangle: Encore.isProduction(),
            sourceMap: !Encore.isProduction(),
            format: {
                comments: !Encore.isProduction(),
            }
        };
    })
    .enableVersioning(true)
    .enableSourceMaps(!Encore.isProduction())
    .addEntry('theme', [
        './assets/theme.js',
    ])
    .addPlugin(new Webpack.DefinePlugin({
        'process.env.WEBPACK_PUBLIC_PATH': JSON.stringify(ASSET_PUBLIC_PATH)
    }))
    .addPlugin(
        new SpriteLoaderPlugin({
            plainSprite: true
        })
    )
	.addLoader({
		test: /\.s[ac]ss$/i,
		use: [
			"style-loader",
			"css-loader",
			"less-loader",
			{
				loader: "sass-loader",
				options: {
					implementation: require('sass'),
				},
			},
		],
	})
    // .addPlugin(
    //     new SimplifyCssModulesPlugin()
    // )
    // .copyFiles([
    //     {from: './assets/images', to: 'images/[path][name].[ext]'},
    // ])
    .configureBabel(function (config) {
        config.plugins.push(['@babel/plugin-proposal-class-properties'])
        config.plugins.push(['@babel/plugin-transform-runtime']);
        config.plugins.push(['@babel/plugin-transform-template-literals', {loose: true}]);

    }, {
        useBuiltIns: 'usage',
        corejs: 3,
        includeNodeModules: ['bootstrap']
    });
;



let webConfig = Encore.getWebpackConfig();
webConfig.name = 'web';

module.exports = [webConfig];
