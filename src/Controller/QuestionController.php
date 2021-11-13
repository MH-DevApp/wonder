<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\Question;
use App\Entity\User;
use App\Entity\Vote;
use App\Form\CommentType;
use App\Form\QuestionType;
use App\Repository\QuestionRepository;
use App\Repository\VoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

class QuestionController extends AbstractController
{
    #[Route('/question/ask', name: 'question_form')]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $question = new Question();
        $formQuestion = $this->createForm(QuestionType::class, $question);
        $formQuestion->handleRequest($request);

        if ($formQuestion->isSubmitted() && $formQuestion->isValid())
        {
            $question->setNbrOfResponse(0);
            $question->setRating(0);
            $question->setAuthor($user);
            $question->setCreatedAt(new \DateTimeImmutable());
            $em->persist($question);
            $em->flush();
            $this->addFlash('success', 'Votre question a bien été ajoutée');
            return $this->redirectToRoute('home');
        }

        return $this->render('question/index.html.twig', [
            'form' => $formQuestion->createView(),
        ]);
    }

    /**
     * @throws NonUniqueResultException
     */
    #[Route('/question/{id}', name: 'question_show')]
    public function show(QuestionRepository $quesitonRepo, int $id, Request $request, EntityManagerInterface $em): Response
    {
        $question = $quesitonRepo->getQuestionWithCommentsAndAuthors($id);
        if (!$question) {
            throw new NotFoundHttpException("Page non trouvée");
        }

        $options = [
            'question' => $question
        ];
        /** @var User $user */
        $user = $this->getUser();

        if ($user) {
            $comment = new Comment();
            $commentForm = $this->createForm(CommentType::class, $comment);
            $commentForm->handleRequest($request);

            if ($commentForm->isSubmitted() && $commentForm->isValid()) {
                $comment->setCreatedAt(new \DateTimeImmutable());
                $comment->setRating(0);
                $comment->setAuthor($user);
                $comment->setQuestion($question);
                $question->setNbrOfResponse($question->getNbrOfResponse() + 1);

                $em->persist($comment);
                $em->flush();
                $this->addFlash('success', 'Votre réponse a bien été ajoutée');

                return $this->redirect($request->getUri());
            }
            $options['form'] = $commentForm->createView();
        }

        return $this->render('question/show.html.twig', $options);
    }

    #[Route('/question/search/{search}', name: 'question_search', defaults: ["search" => "none"], priority: 1)]
    public function questionSearch(string $search, QuestionRepository $questionRepo) : Response
    {
        if ($search === "none") {
            $questions = [];
        } else {
            $questions = $questionRepo->findBySearch($search);
        }

        return $this->json(json_encode($questions));
    }

    #[Route('/question/rating/{id}/{score}', name: "question_rating")]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function ratingQuestion(?Question $question, int $score, EntityManagerInterface $em, Request $request, VoteRepository $voteRepo) : Response
    {
        if (!$question) {
            throw new NotFoundHttpException("Page non trouvée");
        }

        /** @var User $user */
        $user = $this->getUser();
        if ($user !== $question->getAuthor()) {
            $vote = $voteRepo->findOneBy([
                'author' => $user,
                'question' => $question
            ]);

            if ($vote) {
                if (($vote->getIsLike() && $score > 0) || (!$vote->getIsLike() && $score < 0)) {
                    $em->remove($vote);
                    $question->setRating($question->getRating() + ($score > 0 ? -1 : 1));
                } else {
                    $vote->setIsLike(!$vote->getIsLike());
                    $question->setRating($question->getRating() + ($score > 0 ? 2 : -2));
                }
            } else {
                $vote = new Vote();
                $vote->setAuthor($user);
                $vote->setQuestion($question);
                $vote->setIsLike($score > 0);
                $question->setRating($question->getRating() + $score);
                $em->persist($vote);
            }
            $em->flush();
        }

        $referer = $request->server->get('HTTP_REFERER');
        return $referer ? $this->redirect($referer) : $this->redirectToRoute('home');
    }

    #[Route('/comment/rating/{id}/{score}', name: "comment_rating")]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function ratingComment(
        ?Comment $comment,
        int $score,
        EntityManagerInterface $em,
        Request $request,
        VoteRepository $voteRepo
    ) : Response
    {
        if (!$comment) {
            throw new NotFoundHttpException("Page non trouvée");
        }

        /** @var User $user */
        $user = $this->getUser();

        if ($user !== $comment->getAuthor()) {
            $vote = $voteRepo->findOneBy([
                'author' => $user,
                'comment' => $comment
            ]);

            if ($vote) {
                if (($vote->getIsLike() && $score > 0) || (!$vote->getIsLike() && $score < 1)) {
                    $em->remove($vote);
                    $comment->setRating($comment->getRating() + ($score > 0 ? -1 : 1));
                } else {
                    $vote->setIsLike(!$vote->getIsLike());
                    $comment->setRating($comment->getRating() + ($score > 0 ? 2 : -2));
                }
            } else {
                $vote = new Vote();
                $vote->setAuthor($user);
                $vote->setComment($comment);
                $vote->setIsLike($score > 0);
                $comment->setRating($comment->getRating() + $score);
                $em->persist($vote);
            }
            $em->flush();
        }

        $referer = $request->server->get('HTTP_REFERER');
        return $referer ? $this->redirect($referer) : $this->redirectToRoute('home');
    }
}
