module.exports = function( grunt ) {
	grunt.initConfig( {
		pkg: grunt.file.readJSON( "package.json" ),

		phpcs: {
			plugin: {
				src: [ "./*.php", "./includes/*.php", "./tests/*.php" ]
			},
			options: {
				bin: "vendor/bin/phpcs --extensions=php --ignore=\"*/vendor/*,*/node_modules/*\"",
				standard: "phpcs.ruleset.xml"
			}
		},

		jscs: {
			scripts: {
				src: [ "Gruntfile.js", "js/wsu-tls-site.js" ],
				options: {
					preset: "jquery",
					requireCamelCaseOrUpperCaseIdentifiers: false, // We rely on name_name too much to change them all.
					maximumLineLength: 250
				}
			}
		},

		uglify: {
			build: {
				src: "js/wsu-tls-site.js",
				dest: "js/wsu-tls-site.min.js"
			}
		},

		jshint: {
			grunt_script: {
				src: [ "Gruntfile.js" ],
				options: {
					curly: true,
					eqeqeq: true,
					noarg: true,
					quotmark: "double",
					undef: true,
					unused: false,
					node: true     // Define globals available when running in Node.
				}
			},
			tls_script: {
				src: [ "js/wsu-tls-site.js" ],
				options: {
					boss: true,
					curly: true,
					eqeqeq: true,
					eqnull: true,
					expr: true,
					immed: true,
					noarg: true,
					onevar: false,
					smarttabs: true,
					trailing: true,
					undef: true,
					unused: true,
					globals: {
						jQuery: true,
						console: true,
						module: true,
						document: true,
						window:true
					}
				}
			}
		}
	} );

	grunt.loadNpmTasks( "grunt-contrib-uglify" );
	grunt.loadNpmTasks( "grunt-jscs" );
	grunt.loadNpmTasks( "grunt-contrib-jshint" );
	grunt.loadNpmTasks( "grunt-phpcs" );

	// Default task(s).
	grunt.registerTask( "default", [ "phpcs", "jscs", "jshint", "uglify" ] );
};
