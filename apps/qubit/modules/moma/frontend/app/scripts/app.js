'use strict';
// Setup the main module: momaApp
angular.module('momaApp', [
  'momaApp.directives'
])
  .config(function($routeProvider) {
    $routeProvider
      .when('/', {
        templateUrl: Qubit.relativeUrlRoot + '/apps/qubit/modules/moma/frontend/app/views/home.html',
        controller: 'HomeCtrl'
      })
      .when('/artworkrecord', {
        templateUrl: Qubit.relativeUrlRoot + '/apps/qubit/modules/moma/frontend/app/views/artworkrecord.html',
        controller: 'ArtworkRecordCtrl'
      })
      .when('/dashboard', {
        templateUrl: Qubit.relativeUrlRoot + '/apps/qubit/modules/moma/frontend/app/views/dashboard.html',
        controller: 'DashboardCtrl'
      })
      .when('/documentationObject', {
        templateUrl: Qubit.relativeUrlRoot + '/apps/qubit/modules/moma/frontend/app/views/documentationObject.html',
        controller: 'TechnologyRecordCtrl'
      })
    .otherwise({ redirectTo: '/' });
  })

  .config(function ($locationProvider) {
    $locationProvider.html5Mode(false);
  })

  .factory("atomGlobals", function() {
    return {
      relativeUrlRoot: Qubit.relativeUrlRoot
    }
  });

// Setup dependency injection
angular.module('jsPlumb', []);
angular.module('momaApp.directives', ['jsPlumb']);

