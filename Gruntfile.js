module.exports = function(grunt) {
	// Project configuration
	grunt.initConfig({
		pkg: grunt.file.readJSON('package.json'),
		uglify: {
			build: {
				src: 'js/wsu-tls-site.js',
				dest: 'js/wsu-tls-site.min.js'
			}
		},
		jshint: {
			files: ['js/wsu-tls-site.js'],
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
	});

	grunt.loadNpmTasks('grunt-contrib-uglify');
	grunt.loadNpmTasks('grunt-contrib-jshint');

	// Default task(s).
	grunt.registerTask('default', ['jshint', 'uglify']);
};