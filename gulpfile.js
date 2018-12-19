'use strict';

var gulp         = require('gulp'),
    util         = require('util'),
    path         = require('path'),
    immutable    = require('immutable'),
    gulpWebpack  = require('gulp-webpack'),
    webpack      = require('webpack'),
    sourcemaps   = require('gulp-sourcemaps'),
    exec         = require('child_process').execSync,
    sass         = require('gulp-sass'),
    cleancss     = require('gulp-clean-css'),
    csscomb      = require('gulp-csscomb'),
    rename       = require('gulp-rename'),
    autoprefixer = require('gulp-autoprefixer'),
    pwd          = exec('pwd').toString();

// configure the paths
var watch_dir = './scss/**/*.scss';
var src_dir = './scss/*.scss';
var dest_dir = './assets';

var paths = {
    source: src_dir
};

var plugins = {},
    base    = immutable.fromJS(require('./webpack.conf.js')),
    options = {
        prod: base.mergeDeep({
            devtool: 'source-map',
            optimization: {minimize: true},
            plugins: [
                new webpack.DefinePlugin({
                    'process.env': { NODE_ENV: '"production"' }
                }),
                new webpack.ProvidePlugin(plugins)
            ],
            output: {
                filename: 'form.min.js'
            }
        })
    };

// var compileJS = function(watch) {
//     var prodOpts = options.prod.set('watch', watch);
//
//     return gulp.src('app/main.js')
//         .pipe(gulpWebpack(prodOpts.toJS()))
//         .pipe(gulp.dest('assets/'));
// };

var compileCSS = function() {
    return gulp.src(paths.source)
        .pipe(sourcemaps.init())
        .pipe(sass({outputStyle: 'compact', precision: 10})
            .on('error', sass.logError)
        )
        .pipe(sourcemaps.write())
        .pipe(autoprefixer())
        .pipe(gulp.dest(dest_dir))
        .pipe(csscomb())
        .pipe(cleancss())
        .pipe(rename({
            suffix: '.min'
        }))
        .pipe(gulp.dest(dest_dir));
}

// gulp.task('js', function() {
//     compileJS(false);
// });

gulp.task('css', function() {
    compileCSS();
});

gulp.task('watch', function() {
    gulp.watch(watch_dir, ['css']);
    // compileJS(true);
});

gulp.task('all', ['css']);
gulp.task('default', ['all']);