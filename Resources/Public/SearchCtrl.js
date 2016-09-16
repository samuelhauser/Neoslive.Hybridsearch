// PHLU.Corporate:Page.View.Default filter tag navigation


PHLUCorporateApp.directive('search', function () {


    var template = '/_Resources/Static/Packages/PHLU.Corporate/JavaScript/angularjs/PHLU/Corporate/Templates/Search/';

    return {
        template: '<ng-include src="getTemplateUrl()"/>',
        //templateUrl: unfortunately has no access to $scope.user.type
        scope: {
            node: '=data'
        },
        restrict: 'E',
        controller: function ($scope) {

            $scope.getTemplateUrl = function () {
                switch ($scope.node.nodeType) {

                    case 'PHLU.Qmpilot.NodeTypes:File':
                        return template + 'phlu-qmpilot-nodetypes-file.html';

                    case 'PHLU.Corporate:Text':
                        return template + 'phlu-qmpilot-nodetypes-text.html';

                    case 'PHLU.Corporate:Contact':
                        return template + 'phlu-corporate-contact.html';

                    default:
                        return template + 'default.html';

                }


            };

        }
    };


});


PHLUCorporateApp.controller('SearchCtrl', ['$scope', '$timeout', '$cookies', function ($scope) {

    var config, getData, getSearchTerms, resetData, getSpecialTerm, applyData, searchData, database, lunrSearch;
    var filterReg = /[^0-9a-zA-ZöäüÖÄÜ]/g;


    // Initialize Firebase
    config = {
        databaseURL: "https://phlu-f98dd.firebaseio.com",
        storageBucket: "phlu-f98dd.appspot.com",
        workspace: "live",
        dimension: "fb11fdde869d0a8fcfe00a2fd35c031d",
        precision: 2,
        boost: {
            'phlu-corporate-contact-firstname': 100,
            'phlu-corporate-contact-lastname': 300,
            firstname: 100,
            lastname: 100,
            uriPathSegment: 150,
            phone: 35,
            street: 20,
            email: 10,
            title: 40,
            text: 1
        }
    };

    $scope.siteSearch = '';
    $scope.terms = {};
    $scope.searchResult = [];
    $scope.nodes = {};

    // initialize firebase database
    firebase.initializeApp(config);
    database = firebase.database();

    // initialize lunr search engine
    lunrSearch = elasticlunr(function () {
        this.setRef('id');
    });

    var refKeywords = {};
    var refIndex = {};


    // Get a reference to the database service

    getData = function (term) {


        var subterm = term.substr(0, 8);
        var hasdeletion = false;
        var searchString = " " + $scope.siteSearch.toLowerCase() + " ";

        angular.forEach($scope.terms, function (val, key) {
            if (searchString.indexOf(" " + key + " ") < 0) {

                if ($scope.terms[subterm] !== undefined) {
                    delete $scope.terms[subterm];
                }

                if ($scope.terms[key] !== undefined) {
                    delete $scope.terms[key];
                }

                if (refKeywords[subterm] !== undefined) {
                    refKeywords[key].off();
                }

                if (refIndex[key] !== undefined) {
                    refIndex[key].off();
                }

                hasdeletion = true;
            }

        });

        if (hasdeletion) {
            applyData();
        }


        $scope.nodes = {};

        $scope.terms[subterm] = {term: subterm, results: {}};
        refKeywords[subterm] = firebase.database().ref("keywords/" + config.workspace + "/" + config.dimension);

        refKeywords[subterm].orderByKey().equalTo(subterm).on("value", function (dataSnapshot) {

            if (dataSnapshot.val()) {


                refIndex[subterm] = firebase.database().ref("index/" + config.workspace + "/" + config.dimension);
                refIndex[subterm].orderByChild(subterm).on("value", function (snapshot) {

                    $scope.terms[subterm] = {term: subterm, results: {}};

                    snapshot.forEach(function (data) {
                        $scope.terms[subterm].results[data.key] = data.val();
                    });

                    applyData();

                });
            }


        });


    };


    applyData = function () {


        angular.forEach($scope.terms, function (val, key) {

            var sresult = " " + $scope.siteSearch.toLowerCase() + " ";


            angular.forEach(val.results, function (node) {

                var doc = node.__node.properties;

                if (node.__node != undefined) {
                    angular.forEach(node.__node.properties, function (val, key) {
                        if (lunrSearch.getFields().indexOf(key) < 0) {
                            lunrSearch.addField(key);
                        }
                    });

                    doc.id = node.__node.identifier;
                    $scope.nodes[doc.id] = node.__node;
                    lunrSearch.addDoc(doc);

                }

            });


        });


        $scope.terms = {};
        searchData($scope.siteSearch);


    };


    searchData = function (term) {


        var fields = {};


        angular.forEach(lunrSearch.getFields(), function (v, k) {
            fields[v] = {boost: config.boost[v] === undefined ? 1 : config.boost[v]}
        });


        var results = lunrSearch.search(term, {
            fields: fields,
            bool: "OR"
        });

        $scope.searchResult = [];

        angular.forEach(results, function (input, key) {

            if ($scope.nodes[input.ref] !== undefined) {
                $scope.searchResult.push({
                    score: input.score,
                    properties: $scope.nodes[input.ref].properties,
                    grandParentNode: $scope.nodes[input.ref].grandParentNode,
                    nodeType: $scope.nodes[input.ref].nodeType
                });
            }

        });


        setTimeout(function () {
            $scope.$apply(); //this triggers a $digest
        }, 10);


    };


    // Get a reference to the database service

    getSpecialTerm = function (term) {

        // help to find phone numbers
        t = term.replace(/([0-9])( )/i, '$1');
        t = t.replace(/([0-9]{2})/gi, '$1 ');

        return term + " " + t;

    };


    getSearchTerms = function () {


        var terms = [];
        var s = $scope.siteSearch.toLowerCase().replace(filterReg, " ");
        s = getSpecialTerm(s);

        angular.forEach(s.split(" "), function (term, k) {
            term = term.replace(filterReg, "");
            if (term.length > 0) {
                terms.push(term);
            }
        });

        return terms;


    };


    $scope.$watch('siteSearch', function (prop) {

        if (prop == '') {
            $scope.terms = {};
            applyData();

        } else {

            angular.forEach(getSearchTerms(), function (v) {
                if ($scope.terms[v] === undefined) {
                    getData(v);
                }
            });

        }


    });


}]);



